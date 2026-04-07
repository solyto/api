<?php

namespace App\Api\Tags\Controllers;

use App\Api\ApiResponse;
use App\Api\HandlesApiAuth;
use App\Api\Tags\Models\Tag;
use App\Api\Tags\Requests\StoreTagRequest;
use App\Api\Tags\Requests\UpdateTagRequest;
use App\Api\Tags\Resources\TagResource;
use App\Api\Tags\Services\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController
{
    use HandlesApiAuth;

    public function __construct(private readonly TagService $tagService) {}

    /**
     * @OA\Get(
     *     path="/api/tags",
     *     operationId="tagIndex",
     *     tags={"Tags"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Tags retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Tag")),
     *             @OA\Property(property="message", type="string", example="Tags retrieved successfully.")
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            TagResource::collection($this->tagService->list($request->user())),
            'Tags retrieved successfully.'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/tags/{tag}",
     *     operationId="tagShow",
     *     tags={"Tags"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="tag",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Tag retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Tag"),
     *             @OA\Property(property="message", type="string", example="Tag retrieved successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function show(Request $request, Tag $tag): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $tag), 403);

        return ApiResponse::success(new TagResource($tag), 'Tag retrieved successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/tags",
     *     operationId="tagStore",
     *     tags={"Tags"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name"},
     *
     *             @OA\Property(property="name", type="string", maxLength=50),
     *             @OA\Property(property="color", type="string", minLength=7, maxLength=7, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Tag created successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Tag"),
     *             @OA\Property(property="message", type="string", example="Tag created successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(StoreTagRequest $request): JsonResponse
    {
        $tag = $this->tagService->create($request->user(), $request->validated());

        return ApiResponse::success(new TagResource($tag), 'Tag created successfully.', 201);
    }

    /**
     * @OA\Put(
     *     path="/api/tags/{tag}",
     *     operationId="tagUpdate",
     *     tags={"Tags"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="tag",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="color", type="string", minLength=7, maxLength=7, nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Tag updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/Tag"),
     *             @OA\Property(property="message", type="string", example="Tag updated successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function update(UpdateTagRequest $request, Tag $tag): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $tag), 403);

        $tag = $this->tagService->update($tag, $request->validated());

        return ApiResponse::success(new TagResource($tag), 'Tag updated successfully.');
    }

    /**
     * @OA\Delete(
     *     path="/api/tags/{tag}",
     *     operationId="tagDestroy",
     *     tags={"Tags"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="tag",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Tag deleted successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", nullable=true),
     *             @OA\Property(property="message", type="string", example="Tag deleted successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        abort_unless($this->isResourceOwner($request, $tag), 403);

        $this->tagService->destroy($tag);

        return ApiResponse::success(null, 'Tag deleted successfully.');
    }
}
