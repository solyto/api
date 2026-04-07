<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ----------------------------
        // Addressbooks
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE addressbooks (
                id SERIAL NOT NULL,
                principaluri VARCHAR(255),
                displayname VARCHAR(255),
                uri VARCHAR(200),
                description TEXT,
                synctoken INTEGER NOT NULL DEFAULT 1
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY addressbooks
                ADD CONSTRAINT addressbooks_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE UNIQUE INDEX addressbooks_ukey
                ON addressbooks USING btree (principaluri, uri)
        ");

        // ----------------------------
        // Cards
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE cards (
                id SERIAL NOT NULL,
                addressbookid INTEGER NOT NULL,
                carddata BYTEA,
                uri VARCHAR(200),
                lastmodified INTEGER,
                etag VARCHAR(32),
                size INTEGER NOT NULL
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY cards
                ADD CONSTRAINT cards_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE UNIQUE INDEX cards_ukey
                ON cards USING btree (addressbookid, uri)
        ");

        // ----------------------------
        // Addressbook changes
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE addressbookchanges (
                id SERIAL NOT NULL,
                uri VARCHAR(200) NOT NULL,
                synctoken INTEGER NOT NULL,
                addressbookid INTEGER NOT NULL,
                operation SMALLINT NOT NULL
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY addressbookchanges
                ADD CONSTRAINT addressbookchanges_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE INDEX addressbookchanges_addressbookid_synctoken_ix
                ON addressbookchanges USING btree (addressbookid, synctoken)
        ");

        // ----------------------------
        // Calendars
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE calendars (
                id SERIAL NOT NULL,
                synctoken INTEGER NOT NULL DEFAULT 1,
                components VARCHAR(21)
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY calendars
                ADD CONSTRAINT calendars_pkey PRIMARY KEY (id)
        ");

        // ----------------------------
        // Calendar objects
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE calendarobjects (
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
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY calendarobjects
                ADD CONSTRAINT calendarobjects_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE UNIQUE INDEX calendarobjects_ukey
                ON calendarobjects USING btree (calendarid, uri)
        ");

        // ----------------------------
        // Calendar instances
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE calendarinstances (
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
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY calendarinstances
                ADD CONSTRAINT calendarinstances_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE UNIQUE INDEX calendarinstances_principaluri_uri
                ON calendarinstances USING btree (principaluri, uri)
        ");
        DB::connection('pgsql')->statement("
            CREATE UNIQUE INDEX calendarinstances_principaluri_calendarid
                ON calendarinstances USING btree (principaluri, calendarid)
        ");
        DB::connection('pgsql')->statement("
            CREATE UNIQUE INDEX calendarinstances_principaluri_share_href
                ON calendarinstances USING btree (principaluri, share_href)
        ");

        // ----------------------------
        // Calendar subscriptions
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE calendarsubscriptions (
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
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY calendarsubscriptions
                ADD CONSTRAINT calendarsubscriptions_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE UNIQUE INDEX calendarsubscriptions_ukey
                ON calendarsubscriptions USING btree (principaluri, uri)
        ");

        // ----------------------------
        // Calendar changes
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE calendarchanges (
                id SERIAL NOT NULL,
                uri VARCHAR(200) NOT NULL,
                synctoken INTEGER NOT NULL,
                calendarid INTEGER NOT NULL,
                operation SMALLINT NOT NULL DEFAULT 0
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY calendarchanges
                ADD CONSTRAINT calendarchanges_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE INDEX calendarchanges_calendarid_synctoken_ix
                ON calendarchanges USING btree (calendarid, synctoken)
        ");

        // ----------------------------
        // Scheduling objects
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE schedulingobjects (
                id SERIAL NOT NULL,
                principaluri VARCHAR(255),
                calendardata BYTEA,
                uri VARCHAR(200),
                lastmodified INTEGER,
                etag VARCHAR(32),
                size INTEGER NOT NULL
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY schedulingobjects
                ADD CONSTRAINT schedulingobjects_pkey PRIMARY KEY (id)
        ");

        // ----------------------------
        // Locks
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE locks (
                id SERIAL NOT NULL,
                owner VARCHAR(100),
                timeout INTEGER,
                created INTEGER,
                token VARCHAR(100),
                scope SMALLINT,
                depth SMALLINT,
                uri TEXT
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY locks
                ADD CONSTRAINT locks_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE INDEX locks_token_ix
                ON locks USING btree (token)
        ");
        DB::connection('pgsql')->statement("
            CREATE INDEX locks_uri_ix
                ON locks USING btree (uri)
        ");

        // ----------------------------
        // Principals
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE principals (
                id SERIAL NOT NULL,
                uri VARCHAR(200) NOT NULL,
                email VARCHAR(80),
                displayname VARCHAR(80)
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY principals
                ADD CONSTRAINT principals_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE UNIQUE INDEX principals_ukey
                ON principals USING btree (uri)
        ");

        // ----------------------------
        // Group members
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE groupmembers (
                id SERIAL NOT NULL,
                principal_id INTEGER NOT NULL,
                member_id INTEGER NOT NULL
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY groupmembers
                ADD CONSTRAINT groupmembers_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE UNIQUE INDEX groupmembers_ukey
                ON groupmembers USING btree (principal_id, member_id)
        ");

        // ----------------------------
        // Insert default principals
        // ----------------------------
        DB::connection('pgsql')->statement("
            INSERT INTO principals (uri,email,displayname) VALUES
            ('principals/admin', 'admin@example.org','Administrator')
        ");
        DB::connection('pgsql')->statement("
            INSERT INTO principals (uri,email,displayname) VALUES
            ('principals/admin/calendar-proxy-read', NULL, NULL)
        ");
        DB::connection('pgsql')->statement("
            INSERT INTO principals (uri,email,displayname) VALUES
            ('principals/admin/calendar-proxy-write', NULL, NULL)
        ");

        // ----------------------------
        // Property storage
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE propertystorage (
                id SERIAL NOT NULL,
                path VARCHAR(1024) NOT NULL,
                name VARCHAR(100) NOT NULL,
                valuetype INT,
                value BYTEA
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY propertystorage
                ADD CONSTRAINT propertystorage_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE UNIQUE INDEX propertystorage_ukey
                ON propertystorage (path, name)
        ");

        // ----------------------------
        // Users
        // ----------------------------
        DB::connection('pgsql')->statement("
            CREATE TABLE users (
                id SERIAL NOT NULL,
                username VARCHAR(50),
                digesta1 VARCHAR(32)
            )
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE ONLY users
                ADD CONSTRAINT users_pkey PRIMARY KEY (id)
        ");
        DB::connection('pgsql')->statement("
            CREATE UNIQUE INDEX users_ukey
                ON users USING btree (username)
        ");
        DB::connection('pgsql')->statement("
            INSERT INTO users (username,digesta1) VALUES
            ('admin',  '87fd274b7b6c01e48d7c2f965da8ddf7')
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS users CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS propertystorage CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS groupmembers CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS principals CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS locks CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS schedulingobjects CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS calendarchanges CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS calendarsubscriptions CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS calendarinstances CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS calendarobjects CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS calendars CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS addressbookchanges CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS cards CASCADE');
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS addressbooks CASCADE');
    }
};
