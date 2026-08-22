<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Settings;
use App\Services\SettingSchema;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

/**
 * Company-wide defaults.
 *
 * Reading is open to anyone signed in, because the working week, the currency
 * and the length of a working day are what every screen in the product is
 * drawn from. Writing is not: these values decide what payroll pays and what
 * attendance counts as late, so changing one is a system-settings action.
 *
 * A value is never written on trust. Each key has a declared shape and a
 * malformed entry is refused here, where it produces a clear message, rather
 * than three services away in the middle of a payroll run.
 */
final class SettingsController
{
    private Settings $settings;

    public function __construct()
    {
        $this->settings = new Settings();
    }

    /** The effective settings, with any key that was never written filled from its default. */
    public function index(Request $request): Response
    {
        return Response::ok($this->effective(), ['keys' => SettingSchema::keys()]);
    }

    /** Replaces the supplied keys, leaving every other one alone. */
    public function update(Request $request): Response
    {
        $principal = $request->principal();

        $data = Validator::make($request->all(), [
            'settings' => 'required|array',
        ])->validated();

        $errors = [];
        $accepted = [];

        foreach ($data['settings'] as $key => $value) {
            $key = is_string($key) ? trim($key) : '';

            // Unknown keys are refused rather than stored. A typo that is
            // quietly accepted looks like a saved setting and behaves like one
            // that was never changed.
            if (!SettingSchema::isKnown($key)) {
                $errors[$key === '' ? 'settings' : $key] = ['This setting is not recognised.'];

                continue;
            }

            $result = SettingSchema::normalise($key, $value);

            if (!$result['ok']) {
                $errors[$key] = $result['errors'];

                continue;
            }

            $accepted[$key] = $result['value'];
        }

        if ($errors !== []) {
            throw HttpException::unprocessable('Some of the settings supplied are not valid.', $errors);
        }

        if ($accepted === []) {
            throw HttpException::unprocessable('There is nothing to change in this request.');
        }

        $before = $this->settings->all();

        foreach ($accepted as $key => $value) {
            $this->settings->put($key, $value, $principal->userId);
        }

        $after = $this->settings->all();

        AuditLog::record($request, 'identity.settings.updated', 'settings', null, $before, $after, [
            'changed' => AuditLog::diff($before, $after),
        ]);

        return Response::ok($this->effective());
    }

    /**
     * What the platform will actually use, key by key.
     *
     * Every key is always present, so a service reading this never has to
     * carry a fallback of its own and risk two services disagreeing about
     * what the default was.
     *
     * @return array<string, mixed>
     */
    private function effective(): array
    {
        $stored = $this->settings->all();
        $labels = SettingSchema::labels();

        $values = [];
        $catalogue = [];

        foreach (SettingSchema::defaults() as $key => $default) {
            $value = array_key_exists($key, $stored) ? $stored[$key] : $default;

            $values[$key] = $value;
            $catalogue[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'value' => $value,
                'default' => $default,
                'is_default' => $value === $default,
            ];
        }

        return ['settings' => $values, 'catalogue' => $catalogue];
    }
}
