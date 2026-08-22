<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\View;

/**
 * The company directory.
 *
 * The one people-listing everybody may read, and the reason it is a separate
 * page from `/people` rather than a filtered view of it. Employee-service
 * builds the directory response from an explicit allow-list of columns, so
 * salary, date of birth, home address and personal contact details are absent
 * from the payload rather than merely hidden by this template. Nothing here
 * can leak them because nothing here ever receives them.
 */
final class DirectoryController extends Controller
{
    /**
     * A full page of colleagues, because the search box narrows what is
     * already on screen rather than going back to the server for each letter.
     */
    private const PER_PAGE = 100;

    /** The filter pickers are read whole; a second page of them makes no sense. */
    private const REFERENCE_PER_PAGE = 100;

    public function index(): void
    {
        $departmentId = $this->input('department_id');
        $locationId = $this->input('location_id');

        $query = array_filter([
            'department_id' => $departmentId,
            'location_id' => $locationId,
        ], static fn (string $value): bool => $value !== '');

        $query['page'] = $this->page();
        $query['per_page'] = self::PER_PAGE;

        $response = Api::get('/directory', $query);
        $this->guard($response, '/');

        View::render('directory/index', [
            'pageTitle' => 'Directory',
            'breadcrumbs' => [['Directory', '']],
            'people' => is_array($response['data']) ? $response['data'] : [],
            'meta' => $response['meta'],
            // One page each: these are filter pickers, and the default page
            // size would quietly leave offices and departments out of them.
            'departments' => Api::collection('/departments', ['per_page' => self::REFERENCE_PER_PAGE]),
            'locations' => Api::collection('/locations', ['per_page' => self::REFERENCE_PER_PAGE]),
            'filters' => [
                'department_id' => $departmentId,
                'location_id' => $locationId,
            ],
        ]);
    }
}
