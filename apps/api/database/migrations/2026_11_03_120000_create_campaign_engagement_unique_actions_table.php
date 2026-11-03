<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_engagement_unique_actions', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('campaign_id');
            $table->foreignUuid('contact_id');
            $table->string('action', 24);
            $table->foreignUuid('engagement_event_id');
            $table->timestampTz('created_at');
            $table->primary(
                ['workspace_id', 'campaign_id', 'contact_id', 'action'],
                'campaign_unique_action_recipient_pk',
            );
            $table->unique(['workspace_id', 'engagement_event_id'], 'campaign_unique_action_event_uq');
            $table->index(['workspace_id', 'campaign_id', 'created_at'], 'campaign_unique_action_time_idx');
            $table->foreign(['workspace_id', 'campaign_id'], 'campaign_unique_action_campaign_fk')
                ->references(['workspace_id', 'id'])->on('campaigns')->restrictOnDelete();
            $table->foreign(['workspace_id', 'contact_id'], 'campaign_unique_action_contact_fk')
                ->references(['workspace_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'engagement_event_id'], 'campaign_unique_action_event_fk')
                ->references(['workspace_id', 'id'])->on('campaign_engagement_events')->restrictOnDelete();
        });

        // These two /27 blocks are published Google fetcher ranges observed in
        // the supplied visitor log; keep their IP semantics aligned with the
        // live CampaignAutomatedTrafficClassifier during historical backfill.
        $googleProxyAddresses = array_merge(
            array_map(static fn (int $lastOctet): string => '108.177.2.'.$lastOctet, range(0, 31)),
            array_map(static fn (int $lastOctet): string => '192.178.15.'.$lastOctet, range(0, 31)),
        );
        $googleProxyPlaceholders = implode(', ', array_fill(0, count($googleProxyAddresses), '?'));
        $backfillSql = <<<'SQL'
            INSERT INTO campaign_engagement_unique_actions
                (workspace_id, campaign_id, contact_id, action, engagement_event_id, created_at)
            SELECT workspace_id, campaign_id, contact_id, normalized_action, id, created_at
            FROM (
                SELECT workspace_id, campaign_id, contact_id, normalized_action, id, created_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY workspace_id, campaign_id, contact_id, normalized_action
                        ORDER BY created_at, id
                    ) AS action_rank
                FROM (
                    SELECT workspace_id, campaign_id, contact_id,
                        CASE
                            WHEN action IN ('open', 'click') AND (
                                LOWER(user_agent) LIKE '%googleimageproxy%'
                                OR LOWER(user_agent) LIKE '%bot%'
                                OR LOWER(user_agent) LIKE '%googlebot%'
                                OR LOWER(user_agent) LIKE '%bingbot%'
                                OR LOWER(user_agent) LIKE '%yahooproxy%'
                                OR LOWER(user_agent) LIKE '%yahoo! slurp%'
                                OR LOWER(user_agent) LIKE '%proofpoint%'
                                OR LOWER(user_agent) LIKE '%mimecast%'
                                OR LOWER(user_agent) LIKE '%barracuda%'
                                OR LOWER(user_agent) LIKE '%safelinks%'
                                OR LOWER(user_agent) LIKE '%urlscan%'
                                OR LOWER(user_agent) LIKE '%headlesschrome%'
                                OR LOWER(user_agent) LIKE '%phantomjs%'
                                OR LOWER(user_agent) LIKE '%facebookexternalhit%'
                                OR LOWER(user_agent) LIKE '%twitterbot%'
                                OR LOWER(user_agent) LIKE '%slackbot%'
                                OR LOWER(user_agent) LIKE '%discordbot%'
                                OR LOWER(user_agent) LIKE '%telegrambot%'
                                OR LOWER(user_agent) LIKE '%whatsapp%'
                                OR LOWER(user_agent) LIKE '%applebot%'
                                OR LOWER(user_agent) LIKE '%python-requests%'
                                OR LOWER(user_agent) LIKE '%go-http-client%'
                                OR LOWER(user_agent) LIKE '%libwww-perl%'
                                OR LOWER(user_agent) LIKE '%curl/%'
                                OR LOWER(user_agent) LIKE '%curl %'
                                OR LOWER(user_agent) LIKE '%curl'
                                OR LOWER(user_agent) LIKE '%wget/%'
                                OR LOWER(user_agent) LIKE '%wget %'
                                OR LOWER(user_agent) LIKE '%wget'
                                OR LOWER(user_agent) LIKE '%crawler%'
                                OR LOWER(user_agent) LIKE '%spider%'
                                OR LOWER(user_agent) LIKE '%scanner%'
                                OR LOWER(user_agent) LIKE '%linkcheck%'
                                OR LOWER(user_agent) LIKE '%link-check%'
                                OR LOWER(user_agent) LIKE '%preview%'
                                OR LOWER(user_agent) LIKE '%fetcher%'
                                OR (action = 'open' AND client_ip IN (__GOOGLE_PROXY_IPS__))
                            ) THEN 'bot'
                            ELSE action
                        END AS normalized_action,
                        id, created_at
                    FROM campaign_engagement_events
                ) normalized_campaign_actions
            ) ranked_campaign_actions
            WHERE action_rank = 1
            SQL;

        DB::statement(
            str_replace('__GOOGLE_PROXY_IPS__', $googleProxyPlaceholders, $backfillSql),
            $googleProxyAddresses,
        );

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER alphalink_prevent_mutation BEFORE UPDATE OR DELETE ON campaign_engagement_unique_actions FOR EACH ROW EXECUTE FUNCTION alphalink_reject_immutable_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS alphalink_prevent_mutation ON campaign_engagement_unique_actions');
        }

        Schema::dropIfExists('campaign_engagement_unique_actions');
    }
};
