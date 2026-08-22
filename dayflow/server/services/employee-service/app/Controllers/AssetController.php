<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CompanyAssets;
use App\Models\Employees;
use App\Services\RecordId;
use App\Services\RequiredColumns;
use App\Services\SearchTerm;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\ValidationException;
use Dayflow\Kernel\Validation\Validator;

/**
 * Company property: laptops, phones, access cards.
 *
 * Value is submitted as a decimal amount and stored in minor units, so no
 * rounding error can accumulate across an inventory report.
 */
final class AssetController
{
    /** Statuses an asset may be put into directly. */
    private const MANUAL_STATUSES = ['available', 'in_repair', 'retired', 'lost'];

    private const TAG_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9-]{2,29}$/';

    private CompanyAssets $assets;
    private Employees $employees;

    public function __construct()
    {
        $this->assets = new CompanyAssets();
        $this->employees = new Employees();
    }

    public function index(Request $request): Response
    {
        $principal = $request->principal();

        $filters = Validator::make($request->query, [
            'status' => 'nullable|in:' . implode(',', CompanyAssets::STATUSES),
            'category' => 'nullable|in:' . implode(',', CompanyAssets::CATEGORIES),
            'assigned_to' => 'nullable|uuid',
            'search' => 'nullable|safe_text|max:120',
        ])->validated();

        $query = $this->assets->listQuery();

        if ($principal->can(Permissions::ORG_MANAGE)) {
            if (!empty($filters['assigned_to'])) {
                $query->where('company_assets.assigned_to', '=', $filters['assigned_to']);
            }
        } else {
            // An employee sees the equipment issued to them and nothing else.
            // With no person record attached there is nothing to see, and an
            // empty list says so without comparing a blank against a uuid.
            $self = RecordId::orNull($principal->employeeId);

            if ($self === null) {
                $query->whereIn('company_assets.assigned_to', []);
            } else {
                $query->where('company_assets.assigned_to', '=', $self)
                    ->where('company_assets.status', '=', 'assigned');
            }
        }

        if (!empty($filters['status'])) {
            $query->where('company_assets.status', '=', $filters['status']);
        }

        if (!empty($filters['category'])) {
            $query->where('company_assets.category', '=', $filters['category']);
        }

        if (!empty($filters['search'])) {
            $query->where('company_assets.search_text', 'ILIKE', SearchTerm::pattern((string) $filters['search']));
        }

        $query->orderBy('company_assets.asset_tag');

        return Response::page($this->assets->paginate($query, $request->page(), $request->perPage()));
    }

    public function show(Request $request): Response
    {
        $asset = $this->requireAsset($request->route('id'));
        $principal = $request->principal();

        $holder = $asset['assigned_to'] === null ? null : (string) $asset['assigned_to'];

        if (!$principal->can(Permissions::ORG_MANAGE) && !$principal->owns($holder)) {
            throw HttpException::forbidden('You may not view this asset.');
        }

        return Response::ok($asset);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request);

        if ($this->assets->findByTag((string) $data['asset_tag']) !== null) {
            throw HttpException::conflict('An asset already carries that tag.');
        }

        $asset = $this->assets->create(array_merge($data, [
            'status' => $data['status'] ?? 'available',
            'condition' => $data['condition'] ?? 'good',
            'value_minor' => $data['value_minor'] ?? 0,
        ]));

        AuditLog::record($request, 'asset.created', 'company_asset', $asset['id'], [], $asset);

        return Response::created($asset);
    }

    public function update(Request $request): Response
    {
        $id = (string) $this->requireAsset($request->route('id'))['id'];
        $data = $this->validate($request, false);

        if ($data === []) {
            throw HttpException::badRequest('No changes were supplied.');
        }

        if (isset($data['asset_tag'])) {
            $clash = $this->assets->findByTag((string) $data['asset_tag']);

            if ($clash !== null && (string) $clash['id'] !== $id) {
                throw HttpException::conflict('An asset already carries that tag.');
            }
        }

        [$before, $after] = Connection::transaction(function () use ($id, $data): array {
            $before = $this->lockAsset($id);

            // Handing an asset over is a separate action with its own dates, so
            // it cannot be done by editing the status field.
            if (isset($data['status']) && $before['status'] === 'assigned') {
                throw HttpException::conflict('Record the return before changing the status of an issued asset.');
            }

            return [$before, $this->applyChanges($id, $data)];
        });

        AuditLog::record($request, 'asset.updated', 'company_asset', $after['id'], $before, $after);

        return Response::ok($after);
    }

    public function destroy(Request $request): Response
    {
        $id = (string) $this->requireAsset($request->route('id'))['id'];

        $asset = Connection::transaction(function () use ($id): array {
            $asset = $this->lockAsset($id);

            if ($asset['status'] === 'assigned') {
                throw HttpException::conflict('That asset is still issued to somebody. Record its return first.');
            }

            $this->assets->delete($id);

            return $asset;
        });

        AuditLog::record($request, 'asset.deleted', 'company_asset', $id, $asset, []);

        return Response::noContent();
    }

    public function assign(Request $request): Response
    {
        $id = (string) $this->requireAsset($request->route('id'))['id'];

        $data = Validator::make($request->all(), [
            'employee_id' => 'required|uuid',
            'assigned_on' => 'nullable|date|before_or_equal:today',
            'notes' => 'nullable|safe_text|max:1000',
        ])->validated();

        $employee = $this->employees->find((string) $data['employee_id']);

        if ($employee === null) {
            throw new ValidationException('Some of the information supplied is not valid.', [
                'employee_id' => ['That person does not exist.'],
            ]);
        }

        if ($employee['is_active'] !== true) {
            throw HttpException::conflict('That person is no longer active, so nothing can be issued to them.');
        }

        // The status is read and written inside one transaction, under a row
        // lock, so two people cannot both be issued the same laptop.
        [$before, $after] = Connection::transaction(function () use ($id, $data, $employee): array {
            $before = $this->lockAsset($id);

            if ($before['status'] === 'assigned') {
                throw HttpException::conflict('That asset is already issued to somebody.');
            }

            if (!in_array($before['status'], ['available', 'in_repair'], true)) {
                throw HttpException::conflict(sprintf('An asset marked "%s" cannot be issued.', $before['status']));
            }

            return [$before, $this->applyChanges($id, [
                'assigned_to' => $employee['id'],
                'assigned_on' => $data['assigned_on'] ?? Clock::today(),
                'returned_on' => null,
                'status' => 'assigned',
                'notes' => $data['notes'] ?? $before['notes'],
            ])];
        });

        AuditLog::record($request, 'asset.assigned', 'company_asset', $after['id'], $before, $after, [
            'employee_id' => $employee['id'],
        ]);

        return Response::ok($after);
    }

    public function returnAsset(Request $request): Response
    {
        $id = (string) $this->requireAsset($request->route('id'))['id'];

        $data = Validator::make($request->all(), [
            'returned_on' => 'nullable|date|before_or_equal:today',
            'condition' => 'nullable|in:' . implode(',', CompanyAssets::CONDITIONS),
            'status' => 'nullable|in:' . implode(',', self::MANUAL_STATUSES),
            'notes' => 'nullable|safe_text|max:1000',
        ])->validated();

        $returnedOn = (string) ($data['returned_on'] ?? Clock::today());

        [$before, $after] = Connection::transaction(function () use ($id, $data, $returnedOn): array {
            $before = $this->lockAsset($id);

            if ($before['status'] !== 'assigned') {
                throw HttpException::conflict('That asset is not currently issued to anybody.');
            }

            if ($before['assigned_on'] !== null && strtotime($returnedOn) < strtotime((string) $before['assigned_on'])) {
                throw new ValidationException('Some of the information supplied is not valid.', [
                    'returned_on' => ['The return date cannot fall before the date it was issued.'],
                ]);
            }

            // assigned_to is left in place: it is the record of who last held
            // the item, which is exactly what an audit of a lost laptop needs.
            return [$before, $this->applyChanges($id, [
                'returned_on' => $returnedOn,
                'status' => (string) ($data['status'] ?? 'available'),
                'condition' => $data['condition'] ?? $before['condition'],
                'notes' => $data['notes'] ?? $before['notes'],
            ])];
        });

        AuditLog::record($request, 'asset.returned', 'company_asset', $after['id'], $before, $after);

        return Response::ok($after);
    }

    /**
     * Reads an asset under a row lock held for the rest of the transaction.
     *
     * @return array<string, mixed>
     */
    private function lockAsset(string $id): array
    {
        $asset = $this->assets->lockForUpdate($id);

        if ($asset === null) {
            throw HttpException::notFound('That asset does not exist.');
        }

        return $asset;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function applyChanges(string $id, array $changes): array
    {
        $updated = $this->assets->update($id, $changes);

        if ($updated === null) {
            throw HttpException::notFound('That asset does not exist.');
        }

        return $updated;
    }

    /** @return array<string, mixed> */
    private function validate(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'nullable';

        $data = Validator::make($request->all(), [
            'asset_tag' => $required . '|safe_text|max:30',
            'category' => $required . '|in:' . implode(',', CompanyAssets::CATEGORIES),
            'name' => $required . '|safe_text|max:120',
            'serial_number' => 'nullable|safe_text|max:80',
            'purchased_on' => 'nullable|date|before_or_equal:today',
            'value' => 'nullable|money',
            'condition' => 'nullable|in:' . implode(',', CompanyAssets::CONDITIONS),
            'status' => 'nullable|in:' . implode(',', self::MANUAL_STATUSES),
            'notes' => 'nullable|safe_text|max:1000',
        ])->validated();

        if (isset($data['asset_tag'])) {
            if (preg_match(self::TAG_PATTERN, (string) $data['asset_tag']) !== 1) {
                throw new ValidationException('Some of the information supplied is not valid.', [
                    'asset_tag' => ['A tag is 3 to 30 letters, numbers or hyphens.'],
                ]);
            }

            $data['asset_tag'] = strtoupper((string) $data['asset_tag']);
        }

        // The money rule already returned minor units; the column name records
        // that fact so nothing downstream can mistake it for rupees.
        if (array_key_exists('value', $data)) {
            $data['value_minor'] = $data['value'] ?? 0;
            unset($data['value']);
        }

        return RequiredColumns::stripNulls(
            $data,
            ['asset_tag', 'category', 'name', 'condition', 'status', 'value_minor']
        );
    }

    /** @return array<string, mixed> */
    private function requireAsset(string $id): array
    {
        $id = RecordId::orNull($id);
        $asset = $id === null ? null : $this->assets->find($id);

        if ($asset === null) {
            throw HttpException::notFound('That asset does not exist.');
        }

        return $asset;
    }
}
