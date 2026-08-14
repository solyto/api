<?php

use App\Api\ApiResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponseTestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

class ApiResponseTestResourceCollection extends ResourceCollection
{
    public $collects = ApiResponseTestResource::class;
}

describe('ApiResponse::success', function () {
    it('returns a success envelope with defaults', function () {
        $response = ApiResponse::success();

        expect($response)->toBeInstanceOf(\Illuminate\Http\JsonResponse::class);
        expect($response->getStatusCode())->toBe(200);

        $data = $response->getData(true);
        expect($data['success'])->toBeTrue();
        expect($data['message'])->toBe('Success');
        expect($data['data'])->toBeNull();
        expect($data['timestamp'])->toBeString();
        expect($data)->not->toHaveKey('meta');
    });

    it('returns data and a custom message and status', function () {
        $response = ApiResponse::success(['foo' => 'bar'], 'Created', 201);

        expect($response->getStatusCode())->toBe(201);
        $data = $response->getData(true);
        expect($data['success'])->toBeTrue();
        expect($data['message'])->toBe('Created');
        expect($data['data'])->toBe(['foo' => 'bar']);
    });

    it('includes custom meta when provided', function () {
        $response = ApiResponse::success('data', 'Ok', 200, ['version' => 1]);

        $data = $response->getData(true);
        expect($data['meta'])->toBe(['version' => 1]);
    });

    it('transforms a JsonResource', function () {
        $resource = new ApiResponseTestResource((object) ['id' => 1, 'name' => 'John']);
        $response = ApiResponse::success($resource);

        $data = $response->getData(true);
        expect($data['data'])->toBe(['id' => 1, 'name' => 'John']);
    });

    it('transforms a ResourceCollection', function () {
        $collection = new ApiResponseTestResourceCollection([
            (object) ['id' => 1, 'name' => 'John'],
            (object) ['id' => 2, 'name' => 'Jane'],
        ]);
        $response = ApiResponse::success($collection);

        $data = $response->getData(true);
        expect($data['data'])->toBe([
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ]);
    });

    it('transforms a paginator and includes pagination meta', function () {
        $paginator = new LengthAwarePaginator(
            collect([
                (object) ['id' => 1, 'name' => 'John'],
                (object) ['id' => 2, 'name' => 'Jane'],
            ]),
            total: 10,
            perPage: 2,
            currentPage: 1,
        );

        $response = ApiResponse::success($paginator, 'List', 200, ['custom' => 'meta']);

        $data = $response->getData(true);
        expect($data['data'])->toHaveCount(2);
        expect($data['meta']['custom'])->toBe('meta');
        expect($data['meta']['pagination'])->toBe([
            'current_page' => 1,
            'last_page' => 5,
            'per_page' => 2,
            'total' => 10,
            'from' => 1,
            'to' => 2,
            'has_more_pages' => true,
        ]);
    });

    it('includes has_more_pages false on the last page', function () {
        $paginator = new LengthAwarePaginator(
            collect([(object) ['id' => 9], (object) ['id' => 10]]),
            total: 10,
            perPage: 2,
            currentPage: 5,
        );

        $data = ApiResponse::success($paginator)->getData(true);
        expect($data['meta']['pagination']['has_more_pages'])->toBeFalse();
    });
});

describe('ApiResponse::error', function () {
    it('returns an error envelope with defaults', function () {
        $response = ApiResponse::error();

        expect($response->getStatusCode())->toBe(400);
        $data = $response->getData(true);
        expect($data['success'])->toBeFalse();
        expect($data['message'])->toBe('An error occurred');
        expect($data)->not->toHaveKey('errors');
    });

    it('includes errors and meta when provided', function () {
        $response = ApiResponse::error('Bad request', 422, ['field' => ['invalid']], ['code' => 'X']);

        $data = $response->getData(true);
        expect($data['message'])->toBe('Bad request');
        expect($data['errors'])->toBe(['field' => ['invalid']]);
        expect($data['meta'])->toBe(['code' => 'X']);
    });
});

describe('ApiResponse shortcuts', function () {
    it('validationError returns 422 with errors', function () {
        $response = ApiResponse::validationError(['email' => ['The email is required.']]);

        expect($response->getStatusCode())->toBe(422);
        $data = $response->getData(true);
        expect($data['success'])->toBeFalse();
        expect($data['message'])->toBe('Validation failed');
        expect($data['errors'])->toBe(['email' => ['The email is required.']]);
    });

    it('unauthorized returns 401', function () {
        $response = ApiResponse::unauthorized();

        expect($response->getStatusCode())->toBe(401);
        expect($response->getData(true)['message'])->toBe('Unauthorized');
    });

    it('forbidden returns 403', function () {
        $response = ApiResponse::forbidden();

        expect($response->getStatusCode())->toBe(403);
        expect($response->getData(true)['message'])->toBe('Forbidden');
    });

    it('notFound returns 404', function () {
        $response = ApiResponse::notFound();

        expect($response->getStatusCode())->toBe(404);
        expect($response->getData(true)['message'])->toBe('Resource not found');
    });

    it('serverError returns 500', function () {
        $response = ApiResponse::serverError();

        expect($response->getStatusCode())->toBe(500);
        expect($response->getData(true)['message'])->toBe('Internal server error');
    });
});
