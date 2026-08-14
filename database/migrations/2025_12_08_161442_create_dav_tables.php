<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The Sabre DAV stack (DavServerFactory, DAV backends, StatisticsService)
     * hard-codes the 'pgsql' connection. In tests that connection is remapped
     * to the shared SQLite database, so the DDL below is emitted in a
     * driver-aware way: the original PostgreSQL schema is kept for 'pgsql',
     * and an equivalent SQLite schema (same table/column names expected by
     * Sabre's PDO backends) is used for every other driver.
     */
    public function up(): void
    {
        if (DB::connection('pgsql')->getDriverName() === 'pgsql') {
            $this->createPostgresSchema();

            return;
        }

        $this->createSqliteSchema();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'users',
            'propertystorage',
            'groupmembers',
            'principals',
            'locks',
            'schedulingobjects',
            'calendarchanges',
            'calendarsubscriptions',
            'calendarinstances',
            'calendarobjects',
            'calendars',
            'addressbookchanges',
            'cards',
            'addressbooks',
        ];

        $suffix = DB::connection('pgsql')->getDriverName() === 'pgsql' ? ' CASCADE' : '';

        foreach ($tables as $table) {
            DB::connection('pgsql')->statement("DROP TABLE IF EXISTS {$table}{$suffix}");
        }
    }

    private function createPostgresSchema(): void
    {
        $statements = [
            // Addressbooks
            "CREATE TABLE addressbooks (
                id SERIAL NOT NULL,
                principaluri VARCHAR(255),
                displayname VARCHAR(255),
                uri VARCHAR(200),
                description TEXT,
                synctoken INTEGER NOT NULL DEFAULT 1
            )",
            'ALTER TABLE ONLY addressbooks ADD CONSTRAINT addressbooks_pkey PRIMARY KEY (id)',
            'CREATE UNIQUE INDEX addressbooks_ukey ON addressbooks USING btree (principaluri, uri)',

            // Cards
            "CREATE TABLE cards (
                id SERIAL NOT NULL,
                addressbookid INTEGER NOT NULL,
                carddata BYTEA,
                uri VARCHAR(200),
                lastmodified INTEGER,
                etag VARCHAR(32),
                size INTEGER NOT NULL
            )",
            'ALTER TABLE ONLY cards ADD CONSTRAINT cards_pkey PRIMARY KEY (id)',
            'CREATE UNIQUE INDEX cards_ukey ON cards USING btree (addressbookid, uri)',

            // Addressbook changes
            "CREATE TABLE addressbookchanges (
                id SERIAL NOT NULL,
                uri VARCHAR(200) NOT NULL,
                synctoken INTEGER NOT NULL,
                addressbookid INTEGER NOT NULL,
                operation SMALLINT NOT NULL
            )",
            'ALTER TABLE ONLY addressbookchanges ADD CONSTRAINT addressbookchanges_pkey PRIMARY KEY (id)',
            'CREATE INDEX addressbookchanges_addressbookid_synctoken_ix ON addressbookchanges USING btree (addressbookid, synctoken)',

            // Calendars
            "CREATE TABLE calendars (
                id SERIAL NOT NULL,
                synctoken INTEGER NOT NULL DEFAULT 1,
                components VARCHAR(21)
            )",
            'ALTER TABLE ONLY calendars ADD CONSTRAINT calendars_pkey PRIMARY KEY (id)',

            // Calendar objects
            "CREATE TABLE calendarobjects (
                id SERIAL NOT NULL,
                calendardata BYTEA,
                uri VARCHAR(200),
                calendarid INTEGER NOT NULL,
                lastmodified INTEGER,
                etag VARCHAR(32),
                size INTEGER NOT NULL,
                componenttype VARCHAR(8),
                firstoccurence INTEGER,
                lastoccurence INTEGER,
                uid VARCHAR(200)
            )",
            'ALTER TABLE ONLY calendarobjects ADD CONSTRAINT calendarobjects_pkey PRIMARY KEY (id)',
            'CREATE UNIQUE INDEX calendarobjects_ukey ON calendarobjects USING btree (calendarid, uri)',

            // Calendar instances
            "CREATE TABLE calendarinstances (
                id SERIAL NOT NULL,
                calendarid INTEGER NOT NULL,
                principaluri VARCHAR(100),
                access SMALLINT NOT NULL DEFAULT 1,
                displayname VARCHAR(100),
                uri VARCHAR(200),
                description TEXT,
                calendarorder INTEGER NOT NULL DEFAULT 0,
                calendarcolor VARCHAR(10),
                timezone TEXT,
                transparent SMALLINT NOT NULL DEFAULT 0,
                share_href VARCHAR(100),
                share_displayname VARCHAR(100),
                share_invitestatus SMALLINT NOT NULL DEFAULT 2
            )",
            'ALTER TABLE ONLY calendarinstances ADD CONSTRAINT calendarinstances_pkey PRIMARY KEY (id)',
            'CREATE UNIQUE INDEX calendarinstances_principaluri_uri ON calendarinstances USING btree (principaluri, uri)',
            'CREATE UNIQUE INDEX calendarinstances_principaluri_calendarid ON calendarinstances USING btree (principaluri, calendarid)',
            'CREATE UNIQUE INDEX calendarinstances_principaluri_share_href ON calendarinstances USING btree (principaluri, share_href)',

            // Calendar subscriptions
            "CREATE TABLE calendarsubscriptions (
                id SERIAL NOT NULL,
                uri VARCHAR(200) NOT NULL,
                principaluri VARCHAR(100) NOT NULL,
                source TEXT,
                displayname VARCHAR(100),
                refreshrate VARCHAR(10),
                calendarorder INTEGER NOT NULL DEFAULT 0,
                calendarcolor VARCHAR(10),
                striptodos SMALLINT NULL,
                stripalarms SMALLINT NULL,
                stripattachments SMALLINT NULL,
                lastmodified INTEGER
            )",
            'ALTER TABLE ONLY calendarsubscriptions ADD CONSTRAINT calendarsubscriptions_pkey PRIMARY KEY (id)',
            'CREATE UNIQUE INDEX calendarsubscriptions_ukey ON calendarsubscriptions USING btree (principaluri, uri)',

            // Calendar changes
            "CREATE TABLE calendarchanges (
                id SERIAL NOT NULL,
                uri VARCHAR(200) NOT NULL,
                synctoken INTEGER NOT NULL,
                calendarid INTEGER NOT NULL,
                operation SMALLINT NOT NULL DEFAULT 0
            )",
            'ALTER TABLE ONLY calendarchanges ADD CONSTRAINT calendarchanges_pkey PRIMARY KEY (id)',
            'CREATE INDEX calendarchanges_calendarid_synctoken_ix ON calendarchanges USING btree (calendarid, synctoken)',

            // Scheduling objects
            "CREATE TABLE schedulingobjects (
                id SERIAL NOT NULL,
                principaluri VARCHAR(255),
                calendardata BYTEA,
                uri VARCHAR(200),
                lastmodified INTEGER,
                etag VARCHAR(32),
                size INTEGER NOT NULL
            )",
            'ALTER TABLE ONLY schedulingobjects ADD CONSTRAINT schedulingobjects_pkey PRIMARY KEY (id)',

            // Locks
            "CREATE TABLE locks (
                id SERIAL NOT NULL,
                owner VARCHAR(100),
                timeout INTEGER,
                created INTEGER,
                token VARCHAR(100),
                scope SMALLINT,
                depth SMALLINT,
                uri TEXT
            )",
            'ALTER TABLE ONLY locks ADD CONSTRAINT locks_pkey PRIMARY KEY (id)',
            'CREATE INDEX locks_token_ix ON locks USING btree (token)',
            'CREATE INDEX locks_uri_ix ON locks USING btree (uri)',

            // Principals
            "CREATE TABLE principals (
                id SERIAL NOT NULL,
                uri VARCHAR(200) NOT NULL,
                email VARCHAR(80),
                displayname VARCHAR(80)
            )",
            'ALTER TABLE ONLY principals ADD CONSTRAINT principals_pkey PRIMARY KEY (id)',
            'CREATE UNIQUE INDEX principals_ukey ON principals USING btree (uri)',

            // Group members
            "CREATE TABLE groupmembers (
                id SERIAL NOT NULL,
                principal_id INTEGER NOT NULL,
                member_id INTEGER NOT NULL
            )",
            'ALTER TABLE ONLY groupmembers ADD CONSTRAINT groupmembers_pkey PRIMARY KEY (id)',
            'CREATE UNIQUE INDEX groupmembers_ukey ON groupmembers USING btree (principal_id, member_id)',

            // Property storage
            "CREATE TABLE propertystorage (
                id SERIAL NOT NULL,
                path VARCHAR(1024) NOT NULL,
                name VARCHAR(100) NOT NULL,
                valuetype INT,
                value BYTEA
            )",
            'ALTER TABLE ONLY propertystorage ADD CONSTRAINT propertystorage_pkey PRIMARY KEY (id)',
            'CREATE UNIQUE INDEX propertystorage_ukey ON propertystorage (path, name)',

            // Users
            "CREATE TABLE users (
                id SERIAL NOT NULL,
                username VARCHAR(50),
                digesta1 VARCHAR(32)
            )",
            'ALTER TABLE ONLY users ADD CONSTRAINT users_pkey PRIMARY KEY (id)',
            'CREATE UNIQUE INDEX users_ukey ON users USING btree (username)',
        ];

        foreach ($statements as $statement) {
            DB::connection('pgsql')->statement($statement);
        }

        // Insert default principals
        DB::connection('pgsql')->statement("INSERT INTO principals (uri,email,displayname) VALUES ('principals/admin', 'admin@example.org','Administrator')");
        DB::connection('pgsql')->statement("INSERT INTO principals (uri,email,displayname) VALUES ('principals/admin/calendar-proxy-read', NULL, NULL)");
        DB::connection('pgsql')->statement("INSERT INTO principals (uri,email,displayname) VALUES ('principals/admin/calendar-proxy-write', NULL, NULL)");
        DB::connection('pgsql')->statement("INSERT INTO users (username,digesta1) VALUES ('admin',  '87fd274b7b6c01e48d7c2f965da8ddf7')");
    }

    /**
     * SQLite equivalent of the PostgreSQL schema above. The table and column
     * names match exactly what Sabre's PDO backends expect.
     */
    private function createSqliteSchema(): void
    {
        $statements = [
            // Addressbooks
            'CREATE TABLE addressbooks (
                id INTEGER PRIMARY KEY,
                principaluri VARCHAR(255),
                displayname VARCHAR(255),
                uri VARCHAR(200),
                description TEXT,
                synctoken INTEGER NOT NULL DEFAULT 1
            )',
            'CREATE UNIQUE INDEX addressbooks_ukey ON addressbooks (principaluri, uri)',

            // Cards
            'CREATE TABLE cards (
                id INTEGER PRIMARY KEY,
                addressbookid INTEGER NOT NULL,
                carddata BLOB,
                uri VARCHAR(200),
                lastmodified INTEGER,
                etag VARCHAR(32),
                size INTEGER NOT NULL
            )',
            'CREATE UNIQUE INDEX cards_ukey ON cards (addressbookid, uri)',

            // Addressbook changes
            'CREATE TABLE addressbookchanges (
                id INTEGER PRIMARY KEY,
                uri VARCHAR(200) NOT NULL,
                synctoken INTEGER NOT NULL,
                addressbookid INTEGER NOT NULL,
                operation SMALLINT NOT NULL
            )',
            'CREATE INDEX addressbookchanges_addressbookid_synctoken_ix ON addressbookchanges (addressbookid, synctoken)',

            // Calendars
            'CREATE TABLE calendars (
                id INTEGER PRIMARY KEY,
                synctoken INTEGER NOT NULL DEFAULT 1,
                components VARCHAR(21)
            )',

            // Calendar objects
            'CREATE TABLE calendarobjects (
                id INTEGER PRIMARY KEY,
                calendardata BLOB,
                uri VARCHAR(200),
                calendarid INTEGER NOT NULL,
                lastmodified INTEGER,
                etag VARCHAR(32),
                size INTEGER NOT NULL,
                componenttype VARCHAR(8),
                firstoccurence INTEGER,
                lastoccurence INTEGER,
                uid VARCHAR(200)
            )',
            'CREATE UNIQUE INDEX calendarobjects_ukey ON calendarobjects (calendarid, uri)',

            // Calendar instances
            'CREATE TABLE calendarinstances (
                id INTEGER PRIMARY KEY,
                calendarid INTEGER NOT NULL,
                principaluri VARCHAR(100),
                access SMALLINT NOT NULL DEFAULT 1,
                displayname VARCHAR(100),
                uri VARCHAR(200),
                description TEXT,
                calendarorder INTEGER NOT NULL DEFAULT 0,
                calendarcolor VARCHAR(10),
                timezone TEXT,
                transparent SMALLINT NOT NULL DEFAULT 0,
                share_href VARCHAR(100),
                share_displayname VARCHAR(100),
                share_invitestatus SMALLINT NOT NULL DEFAULT 2
            )',
            'CREATE UNIQUE INDEX calendarinstances_principaluri_uri ON calendarinstances (principaluri, uri)',
            'CREATE UNIQUE INDEX calendarinstances_principaluri_calendarid ON calendarinstances (principaluri, calendarid)',
            'CREATE UNIQUE INDEX calendarinstances_principaluri_share_href ON calendarinstances (principaluri, share_href)',

            // Calendar subscriptions
            'CREATE TABLE calendarsubscriptions (
                id INTEGER PRIMARY KEY,
                uri VARCHAR(200) NOT NULL,
                principaluri VARCHAR(100) NOT NULL,
                source TEXT,
                displayname VARCHAR(100),
                refreshrate VARCHAR(10),
                calendarorder INTEGER NOT NULL DEFAULT 0,
                calendarcolor VARCHAR(10),
                striptodos SMALLINT NULL,
                stripalarms SMALLINT NULL,
                stripattachments SMALLINT NULL,
                lastmodified INTEGER
            )',
            'CREATE UNIQUE INDEX calendarsubscriptions_ukey ON calendarsubscriptions (principaluri, uri)',

            // Calendar changes
            'CREATE TABLE calendarchanges (
                id INTEGER PRIMARY KEY,
                uri VARCHAR(200) NOT NULL,
                synctoken INTEGER NOT NULL,
                calendarid INTEGER NOT NULL,
                operation SMALLINT NOT NULL DEFAULT 0
            )',
            'CREATE INDEX calendarchanges_calendarid_synctoken_ix ON calendarchanges (calendarid, synctoken)',

            // Scheduling objects
            'CREATE TABLE schedulingobjects (
                id INTEGER PRIMARY KEY,
                principaluri VARCHAR(255),
                calendardata BLOB,
                uri VARCHAR(200),
                lastmodified INTEGER,
                etag VARCHAR(32),
                size INTEGER NOT NULL
            )',

            // Locks
            'CREATE TABLE locks (
                id INTEGER PRIMARY KEY,
                owner VARCHAR(100),
                timeout INTEGER,
                created INTEGER,
                token VARCHAR(100),
                scope SMALLINT,
                depth SMALLINT,
                uri TEXT
            )',
            'CREATE INDEX locks_token_ix ON locks (token)',
            'CREATE INDEX locks_uri_ix ON locks (uri)',

            // Principals
            'CREATE TABLE principals (
                id INTEGER PRIMARY KEY,
                uri VARCHAR(200) NOT NULL,
                email VARCHAR(80),
                displayname VARCHAR(80)
            )',
            'CREATE UNIQUE INDEX principals_ukey ON principals (uri)',

            // Group members
            'CREATE TABLE groupmembers (
                id INTEGER PRIMARY KEY,
                principal_id INTEGER NOT NULL,
                member_id INTEGER NOT NULL
            )',
            'CREATE UNIQUE INDEX groupmembers_ukey ON groupmembers (principal_id, member_id)',

            // Property storage
            'CREATE TABLE propertystorage (
                id INTEGER PRIMARY KEY,
                path VARCHAR(1024) NOT NULL,
                name VARCHAR(100) NOT NULL,
                valuetype INT,
                value BLOB
            )',
            'CREATE UNIQUE INDEX propertystorage_ukey ON propertystorage (path, name)',

            // Users
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username VARCHAR(50),
                digesta1 VARCHAR(32)
            )',
            'CREATE UNIQUE INDEX users_ukey ON users (username)',
        ];

        foreach ($statements as $statement) {
            DB::connection('pgsql')->statement($statement);
        }

        // Insert default principals
        DB::connection('pgsql')->statement("INSERT INTO principals (uri,email,displayname) VALUES ('principals/admin', 'admin@example.org','Administrator')");
        DB::connection('pgsql')->statement("INSERT INTO principals (uri,email,displayname) VALUES ('principals/admin/calendar-proxy-read', NULL, NULL)");
        DB::connection('pgsql')->statement("INSERT INTO principals (uri,email,displayname) VALUES ('principals/admin/calendar-proxy-write', NULL, NULL)");
        DB::connection('pgsql')->statement("INSERT INTO users (username,digesta1) VALUES ('admin',  '87fd274b7b6c01e48d7c2f965da8ddf7')");
    }
};
