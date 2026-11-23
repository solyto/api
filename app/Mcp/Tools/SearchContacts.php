<?php

namespace App\Mcp\Tools;

use App\Api\Contacts\Resources\ContactResource;
use App\Api\Contacts\Services\ContactService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Search the user\'s contacts by name, email address or organization.')]
class SearchContacts extends SolytoTool
{
    private const int MAX_RESULTS = 20;
    private const array OMITTED_FIELDS = ['address_book_color', 'etag', 'has_photo'];

    public function __construct(private readonly ContactService $contacts) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['query' => ['required', 'string', 'min:2', 'max:200']]);
        $query = mb_strtolower($validated['query']);
        $contacts = ContactResource::collection($this->contacts->listContacts($this->user($request)))->resolve(request());

        $matches = collect($contacts)
            ->filter(fn (array $contact) => str_contains($this->searchableText($contact), $query))
            ->take(self::MAX_RESULTS)
            ->map(fn (array $contact) => Arr::except($contact, self::OMITTED_FIELDS))
            ->values();

        return Response::json($matches);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Text to look for in names, email addresses and organizations.')->required(),
        ];
    }

    private function searchableText(array $contact): string
    {
        $emails = Arr::flatten($contact['email'] ?? []);

        return mb_strtolower(implode(' ', [$contact['full_name'], $contact['organization'], ...$emails]));
    }
}
