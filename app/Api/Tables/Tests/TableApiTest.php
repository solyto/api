<?php

use App\Api\Tables\Enums\TableColumnTypeEnum;
use App\Api\Tables\Models\Table;
use App\Api\Tables\Models\TableColumn;
use App\Api\Tables\Models\TableRow;
use App\Api\Tables\Services\TableColumnService;
use App\Api\Tables\Services\TableRowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

describe('Table CRUD', function () {
    it('lists, stores, shows, updates and deletes tables', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tables')
            ->assertStatus(200)->assertJsonCount(0, 'data');

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/tables', ['name' => 'Music Equipment'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Music Equipment')
            ->assertJsonPath('data.view', 'list');

        $tableId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tables/'.$tableId)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $tableId)
            ->assertJsonPath('data.columns', [])
            ->assertJsonPath('data.rows', []);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/tables/'.$tableId, ['name' => 'Gear', 'view' => 'card'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Gear')
            ->assertJsonPath('data.view', 'card');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tables/'.$tableId)
            ->assertStatus(200);

        expect(Table::count())->toBe(0);
    });

    it('scopes tables to the authenticated user', function () {
        $user = makeUser();
        $other = makeUser();
        $table = Table::factory()->forUser($other)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tables')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tables/'.$table->id)
            ->assertStatus(403);
    });

    it('reorders tables', function () {
        $user = makeUser();
        $first = Table::factory()->forUser($user)->create(['position' => 0]);
        $second = Table::factory()->forUser($user)->create(['position' => 1]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/tables/reorder', ['ids' => [$second->id, $first->id]])
            ->assertStatus(200);

        expect($first->fresh()->position)->toBe(1);
        expect($second->fresh()->position)->toBe(0);
    });
});

describe('Table columns', function () {
    it('creates, updates, reorders and deletes columns', function () {
        $user = makeUser();
        $table = Table::factory()->forUser($user)->create();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/columns", ['name' => 'Title', 'type' => 'text'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Title')
            ->assertJsonPath('data.type', 'text');

        $columnId = $store->json('data.id');

        $select = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/columns", [
                'name' => 'Status',
                'type' => 'select',
                'options' => ['New', 'Used'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.options', ['New', 'Used']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/tables/{$table->id}/columns/{$columnId}", ['name' => 'Item title'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Item title');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/tables/{$table->id}/columns/reorder", [
                'ids' => [$select->json('data.id'), $columnId],
            ])
            ->assertStatus(200);

        expect(TableColumn::find($columnId)->position)->toBe(1);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/tables/{$table->id}/columns/{$columnId}")
            ->assertStatus(200);

        expect(TableColumn::count())->toBe(1);
    });

    it('rejects an unknown column type', function () {
        $user = makeUser();
        $table = Table::factory()->forUser($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/columns", ['name' => 'Bad', 'type' => 'nonsense'])
            ->assertStatus(422);
    });

    it('strips a deleted column value out of every row', function () {
        $user = makeUser();
        $table = Table::factory()->forUser($user)->create();
        $column = TableColumn::factory()->forTable($table)->create();
        $row = TableRow::factory()->forTable($table)->withData([$column->id => 'value'])->create();

        app(TableColumnService::class)->destroy($column);

        expect($row->fresh()->data)->toBe([]);
    });
});

describe('Table rows', function () {
    it('creates, updates, reorders and deletes rows, dropping unknown column values', function () {
        $user = makeUser();
        $table = Table::factory()->forUser($user)->create();
        $column = TableColumn::factory()->forTable($table)->create();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/rows", [
                'data' => [$column->id => 'Fender Stratocaster', 'unknown-column' => 'ignored'],
            ])
            ->assertStatus(201);

        $rowId = $store->json('data.id');
        expect($store->json('data.data'))->toBe([$column->id => 'Fender Stratocaster']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/tables/{$table->id}/rows/{$rowId}", [
                'data' => [$column->id => 'Gibson Les Paul'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.data.'.$column->id, 'Gibson Les Paul');

        $second = TableRow::factory()->forTable($table)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/tables/{$table->id}/rows/reorder", [
                'ids' => [$second->id, $rowId],
            ])
            ->assertStatus(200);

        expect(TableRow::find($rowId)->position)->toBe(1);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/tables/{$table->id}/rows/{$rowId}")
            ->assertStatus(200);

        expect(TableRow::count())->toBe(1);
    });

    it('forbids acting on rows of a table the user does not own', function () {
        $user = makeUser();
        $other = makeUser();
        $table = Table::factory()->forUser($other)->create();
        $row = TableRow::factory()->forTable($table)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/rows", ['data' => []])
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/tables/{$table->id}/rows/{$row->id}")
            ->assertStatus(403);
    });
});

describe('Table images', function () {
    it('uploads and serves a picture cell image', function () {
        $user = makeUser();
        $table = Table::factory()->forUser($user)->create();
        TableColumn::factory()->forTable($table)->ofType(TableColumnTypeEnum::PICTURE)->create();

        $file = UploadedFile::fake()->image('amp.jpg');

        $upload = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/tables/{$table->id}/images", ['image' => $file])
            ->assertStatus(201);

        $fileName = $upload->json('data.file_name');
        expect($fileName)->not->toBeNull();

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/tables/{$table->id}/images/{$fileName}")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'image/jpeg');
    });

    it('rejects non-image uploads', function () {
        $user = makeUser();
        $table = Table::factory()->forUser($user)->create();

        $file = UploadedFile::fake()->create('document.pdf', 10, 'application/pdf');

        $this->actingAs($user, 'sanctum')
            ->post("/api/v1/tables/{$table->id}/images", ['image' => $file])
            ->assertStatus(422);
    });
});

describe('TableRowService', function () {
    it('only keeps data for columns that exist on the table', function () {
        $user = makeUser();
        $table = Table::factory()->forUser($user)->create();
        $column = TableColumn::factory()->forTable($table)->create();

        $row = app(TableRowService::class)->create($table, [
            'data' => [$column->id => 'Kept', 'ghost-column' => 'Dropped'],
        ]);

        expect($row->data)->toBe([$column->id => 'Kept']);
    });
});
