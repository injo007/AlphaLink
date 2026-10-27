import unittest

from audit.tools.dms_reference_readonly_relay import (
    BULK_INSTALL_PAGE_PATH,
    CAMPAIGNS_CREATE_PAGE_PATH,
    CAMPAIGNS_INDEX_PAGE_PATH,
    MAILER_SERVERS_INDEX_PAGE_PATH,
    CLOUD_PROVIDERS_INDEX_PAGE_PATH,
    DOMAINS_INDEX_PAGE_PATH,
    DOMAIN_PROVIDERS_INDEX_PAGE_PATH,
    GLOBAL_TESTS_PAGE_PATH,
    GLOBAL_TEST_HISTORIES_PAGE_PATH,
    WARMUPS_INDEX_PAGE_PATH,
    WARMUPS_CREATE_PAGE_PATH,
    WARMUPS_PROCESSES_PAGE_PATH,
    WARMUPS_SEEDS_PAGE_PATH,
    WARMUPS_SEEDS_CREATE_PAGE_PATH,
    WARMUPS_SEEDS_CLEAN_PAGE_PATH,
    WARMUPS_STATISTICS_PAGE_PATH,
    WARMUP_PAGE_PATHS,
    ISPS_CREATE_PAGE_PATH,
    ISPS_INDEX_PAGE_PATH,
    INSTALL_SETTINGS_PAGE_PATH,
    MY_SPONSORS_PAGE_PATH,
    OFFERS_CREATE_PAGE_PATH,
    OFFERS_INDEX_PAGE_PATH,
    PMTA_PAGE_PATHS,
    PROFILE_PATH,
    SPONSORS_CREATE_PAGE_PATH,
    SPONSORS_INDEX_PAGE_PATH,
    SERVERS_PROVIDERS_CREATE_PAGE_PATH,
    SERVERS_PROVIDERS_INDEX_PAGE_PATH,
    READ_ONLY_PAGE_PATHS,
    REFERENCE_ORIGIN,
    is_protected_table_data_query,
    local_path_for,
    is_protected_pmta_data_request,
    is_isp_catalog_data_request,
    rewrite_document,
    sanitize_dms_install_settings_html,
    sanitize_dms_campaign_create_html,
    sanitize_dms_profile_html,
    sanitize_dms_pmta_html,
    is_protected_campaign_catalog_request,
    is_protected_global_test_data_request,
    sanitize_dms_global_tests_html,
    sanitize_dms_warmup_html,
    synthetic_empty_global_test_response,
    is_protected_warmup_data_request,
    synthetic_empty_warmup_response,
    synthetic_empty_campaign_catalog_response,
    synthetic_empty_datatable_response,
    synthetic_empty_list_response,
    synthetic_empty_pmta_response,
)


class ReferenceListPrivacyTests(unittest.TestCase):
    def test_warmup_pages_are_allowlisted_and_dynamic_rows_are_intercepted(self):
        self.assertTrue(WARMUP_PAGE_PATHS.issubset(READ_ONLY_PAGE_PATHS))
        self.assertEqual(len(WARMUP_PAGE_PATHS), 7)
        for unsafe_path in (
            "/mailer/drops",
            "/mailer/warmups/42",
            "/mailer/warmups/42/monitor",
            "/mailer/warmups/seeds/load",
            "/mailer/warmups/seeds/42/show",
        ):
            self.assertNotIn(unsafe_path, READ_ONLY_PAGE_PATHS)
        self.assertFalse(is_protected_warmup_data_request(WARMUPS_INDEX_PAGE_PATH, ""))
        self.assertTrue(is_protected_warmup_data_request(WARMUPS_INDEX_PAGE_PATH, "from=2026-10-25&to=2026-10-25"))
        self.assertTrue(is_protected_warmup_data_request(WARMUPS_PROCESSES_PAGE_PATH, "", "application/json"))
        self.assertTrue(is_protected_warmup_data_request(WARMUPS_SEEDS_PAGE_PATH, "draw=3&start=0"))
        self.assertTrue(is_protected_warmup_data_request(WARMUPS_STATISTICS_PAGE_PATH, "from=2026-10-25&to=2026-10-25"))
        self.assertFalse(is_protected_warmup_data_request(WARMUPS_CREATE_PAGE_PATH, ""))
        self.assertEqual(synthetic_empty_warmup_response(WARMUPS_INDEX_PAGE_PATH, ""), b'{"data": []}')
        self.assertEqual(synthetic_empty_warmup_response(WARMUPS_PROCESSES_PAGE_PATH, ""), b'{"data": []}')
        self.assertEqual(synthetic_empty_warmup_response(WARMUPS_SEEDS_PAGE_PATH, "draw=3&start=0"),
                         b'{"draw": 3, "recordsTotal": 0, "recordsFiltered": 0, "data": []}')
        self.assertEqual(synthetic_empty_warmup_response(WARMUPS_STATISTICS_PAGE_PATH, "from=x&to=y"), b"[]")

    def test_warmup_sanitizer_keeps_form_rules_and_removes_private_values_and_scripts(self):
        source = (
            '<h1>NEW WARMUP</h1><button id="create_warmup">CREATE WARMUP</button>'
            '<input id="return_path" value="private@example.net">'
            '<select id="option"><option value="ip" selected>IP</option><option value="vmta">VMTA</option>'
            '<option value="smtp">SMTP</option></select>'
            '<select id="servers"><option value="server-secret">Private Server | 198.51.100.8</option></select>'
            '<textarea id="header">Subject: private subject</textarea>'
            '<textarea id="body">private body</textarea>'
            '<script>sendWarmup("private@example.net");</script>'
            '<table><tr><th>Name</th></tr><tr><td>private warmup</td></tr></table>'
        )

        result = sanitize_dms_warmup_html(source, WARMUPS_CREATE_PAGE_PATH)

        for private_value in (
            "private@example.net", "server-secret", "198.51.100.8", "private subject", "private body",
            "sendWarmup", "private warmup",
        ):
            self.assertNotIn(private_value, result)
        self.assertIn('<option value="ip" selected>IP</option>', result)
        self.assertIn('<option value="vmta">VMTA</option>', result)
        self.assertIn('<select id="servers"></select>', result)
        self.assertIn("CREATE WARMUP", result)

    def test_warmup_sanitizer_fails_closed_for_unexpected_page_or_path(self):
        with self.assertRaises(ValueError):
            sanitize_dms_warmup_html("<main><h1>Unexpected</h1></main>", WARMUPS_INDEX_PAGE_PATH)
        with self.assertRaises(ValueError):
            sanitize_dms_warmup_html("<h1>NEW WARMUP</h1><select id='servers'></select>", "/mailer/warmups/42")

    def test_global_test_pages_are_allowlisted_while_logs_and_catalogs_are_redacted(self):
        self.assertIn(GLOBAL_TESTS_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertIn(GLOBAL_TEST_HISTORIES_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertFalse(is_protected_global_test_data_request(GLOBAL_TESTS_PAGE_PATH, ""))
        self.assertTrue(is_protected_global_test_data_request(GLOBAL_TESTS_PAGE_PATH, "recipient=private"))
        self.assertTrue(is_protected_global_test_data_request(GLOBAL_TEST_HISTORIES_PAGE_PATH, "draw=2&start=0"))
        self.assertTrue(is_protected_global_test_data_request("/mailer/global-tests/history/42", ""))
        self.assertEqual(
            synthetic_empty_global_test_response(GLOBAL_TEST_HISTORIES_PAGE_PATH, "draw=2&start=0"),
            b'{"draw": 2, "recordsTotal": 0, "recordsFiltered": 0, "data": []}',
        )

    def test_global_test_sanitizer_removes_addresses_message_bodies_options_and_inline_data(self):
        source = (
            '<h1>Global Tests</h1>'
            '<form action="/mailer/global-tests"><input id="test-address" value="private@example.net">'
            '<select id="servers"><option value="server-secret">Secret Server | 198.51.100.7</option></select>'
            '<textarea id="email-test">private@example.net</textarea>'
            '<textarea id="header">Subject: private subject</textarea>'
            '<textarea id="body">private body</textarea>'
            '<button onclick="sendPrivateCanary()">SEND TEST</button></form>'
            '<script>const recipient = "private@example.net";</script>'
            '<table><tr><th>Recipient</th></tr><tr><td>private@example.net</td></tr></table>'
            '<a href="/mailer/global-tests/history/42?recipient=private">History</a>'
        )

        result = sanitize_dms_global_tests_html(source)

        for private_value in (
            "private@example.net", "server-secret", "198.51.100.7", "private subject",
            "private body", "sendPrivateCanary", "recipient=private",
        ):
            self.assertNotIn(private_value, result)
        self.assertIn("SEND TEST", result)
        self.assertIn("<table><tr><th>Recipient</th></tr></table>", result)
        self.assertIn("/mailer/global-tests/history/42", result)

    def test_global_test_sanitizer_fails_closed_for_unexpected_page(self):
        with self.assertRaises(ValueError):
            sanitize_dms_global_tests_html("<main><h1>Unexpected page</h1></main>")

    def test_malformed_reference_url_is_blocked_without_breaking_the_page(self):
        self.assertEqual(
            local_path_for("//[invalid-host", "https://dms.example/mailer/pmta"),
            "#external-reference-blocked",
        )

    def test_pmta_pages_are_allowlisted_but_server_ip_config_and_history_data_are_replaced(self):
        self.assertEqual(
            PMTA_PAGE_PATHS,
            {
                "/mailer/pmta", "/mailer/pmta/scheduler", "/mailer/pmta/settings",
                "/mailer/pmta/vmta", "/mailer/pmta/smtp", "/mailer/pmta/bounces",
                "/mailer/pmta/action-history", "/mailer/pmta/config-history",
            },
        )
        self.assertTrue(PMTA_PAGE_PATHS.issubset(READ_ONLY_PAGE_PATHS))
        self.assertTrue(is_protected_pmta_data_request("/mailer/ips/servers", ""))
        self.assertTrue(is_protected_pmta_data_request("/mailer/pmta/my-vmta", ""))
        self.assertTrue(is_protected_pmta_data_request("/mailer/pmta/my-smtp", ""))
        self.assertTrue(is_protected_pmta_data_request("/mailer/pmta/file", "server=secret"))
        self.assertFalse(is_protected_pmta_data_request("/mailer/pmta/settings", ""))
        self.assertTrue(is_protected_pmta_data_request("/mailer/pmta/settings", "server=secret"))
        self.assertTrue(is_protected_pmta_data_request("/mailer/pmta/config-history", "draw=2&start=0"))
        self.assertFalse(is_protected_pmta_data_request("/mailer/pmta", "ids[]=secret"))
        self.assertEqual(synthetic_empty_pmta_response("/mailer/ips/servers", ""), b"[]")
        self.assertEqual(synthetic_empty_pmta_response("/mailer/pmta/my-vmta", ""), b'{"data": []}')
        self.assertEqual(
            synthetic_empty_pmta_response("/mailer/pmta/config-history", "draw=2&start=0"),
            b'{"draw": 2, "recordsTotal": 0, "recordsFiltered": 0, "data": []}',
        )

    def test_pmta_page_sanitizer_removes_live_server_and_ip_options_but_keeps_all_filter(self):
        source = (
            '<select id="servers"><option value="server-secret">Private server | 198.51.100.7</option></select>'
            '<select id="ips"><option value="ip-secret">Private server | 203.0.113.8</option></select>'
            '<select id="server"><option value="0" selected>ALL</option>'
            '<option value="history-secret">Private history server</option></select>'
        )

        result = sanitize_dms_pmta_html(source)

        for secret in ("server-secret", "198.51.100.7", "ip-secret", "203.0.113.8", "history-secret", "Private history server"):
            self.assertNotIn(secret, result)
        self.assertIn('<option value="0" selected>ALL</option>', result)

    def test_campaign_page_is_allowlisted_but_campaign_records_are_replaced(self):
        self.assertIn(CAMPAIGNS_INDEX_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertFalse(is_protected_table_data_query(CAMPAIGNS_INDEX_PAGE_PATH, ""))
        self.assertTrue(is_protected_table_data_query(CAMPAIGNS_INDEX_PAGE_PATH, "draw=1&start=0"))
        self.assertTrue(is_protected_table_data_query(CAMPAIGNS_INDEX_PAGE_PATH, "", "application/json"))
        self.assertTrue(is_protected_table_data_query(CAMPAIGNS_INDEX_PAGE_PATH, "", requested_with="XMLHttpRequest"))

    def test_mailer_server_page_is_allowlisted_but_server_rows_are_replaced(self):
        self.assertIn(MAILER_SERVERS_INDEX_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertFalse(is_protected_table_data_query(MAILER_SERVERS_INDEX_PAGE_PATH, ""))
        self.assertTrue(is_protected_table_data_query(MAILER_SERVERS_INDEX_PAGE_PATH, "draw=1&start=0"))
        self.assertTrue(is_protected_table_data_query(MAILER_SERVERS_INDEX_PAGE_PATH, "", "application/json"))
        self.assertTrue(is_protected_table_data_query(MAILER_SERVERS_INDEX_PAGE_PATH, "", requested_with="XMLHttpRequest"))

    def test_campaign_create_form_is_allowlisted_and_private_options_are_removed(self):
        self.assertIn(CAMPAIGNS_CREATE_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        source = (
            '<h1>CREATE CAMPAIGN</h1>'
            '<select id="sponsor"><option value="secret-sponsor">Secret sponsor</option></select>'
            '<select id="saved_headers"><option value="secret-header">Secret header</option></select>'
            '<select id="domain"><option value="secret.example">secret.example</option></select>'
            '<select id="negative"><option value="secret-negative">Secret negative</option></select>'
            '<select id="servers"><option value="secret-server">Secret server and IP</option></select>'
        )

        result = sanitize_dms_campaign_create_html(source)

        for secret in ("secret-sponsor", "secret-header", "secret.example", "secret-negative", "secret-server"):
            self.assertNotIn(secret, result)
        self.assertIn("Select Sponsor", result)
        self.assertIn("Select Header", result)
        self.assertIn("Select Domain", result)
        self.assertIn("Select Negative", result)
        self.assertIn("Select Server", result)

    def test_campaign_create_catalog_requests_are_intercepted_with_empty_shapes(self):
        self.assertFalse(is_protected_campaign_catalog_request(CAMPAIGNS_CREATE_PAGE_PATH, ""))
        self.assertTrue(is_protected_campaign_catalog_request(CAMPAIGNS_CREATE_PAGE_PATH, "sponsors=true"))
        self.assertFalse(is_protected_campaign_catalog_request(CAMPAIGNS_INDEX_PAGE_PATH, "offers=true"))
        self.assertEqual(
            synthetic_empty_campaign_catalog_response("assets=true&offer_id=secret-id"),
            b'{"from": [], "subjects": [], "creatives": [], "unsubscribe": null, "html": []}',
        )
        self.assertEqual(synthetic_empty_campaign_catalog_response("offers=true"), b'{"data": []}')
        self.assertEqual(synthetic_empty_campaign_catalog_response("isps=true"), b'[]')

    def test_profile_page_exposes_only_the_authenticated_username(self):
        self.assertIn(PROFILE_PATH, READ_ONLY_PAGE_PATHS)
        source = (
            '<section><div><div>Full Name</div><a>Private Full Name</a></div>'
            '<div><div>Username</div><a>synthetic-admin</a></div>'
            '<div><div>Type</div><a>Super Admin</a></div></section>'
            '<form><input name="_token" value="private-csrf-canary">'
            '<input type="password" name="current_password"></form>'
        )

        result = sanitize_dms_profile_html(source)

        self.assertIn('<p id="dms-username">synthetic-admin</p>', result)
        self.assertNotIn('Private Full Name', result)
        self.assertNotIn('Super Admin', result)
        self.assertNotIn('private-csrf-canary', result)
        self.assertNotIn('current_password', result)

    def test_profile_sanitizer_fails_closed_if_username_field_is_missing_or_ambiguous(self):
        with self.assertRaises(ValueError):
            sanitize_dms_profile_html('<div><div>Full Name</div><a>Only Name</a></div>')
        with self.assertRaises(ValueError):
            sanitize_dms_profile_html(
                '<div><div>Username</div><a>one</a></div>'
                '<div><div>Username</div><a>two</a></div>'
            )

    def test_profile_route_rewrite_returns_only_username_evidence(self):
        source = (
            '<div><div>Username</div><a href="javascript:void(0)">synthetic-admin</a></div>'
            '<div><div>Full Name</div><a>private-name</a></div>'
        )

        result = rewrite_document(source, 'text/html', f'https://dms.example{PROFILE_PATH}')

        self.assertIn('synthetic-admin', result)
        self.assertNotIn('private-name', result)

    def test_installer_settings_page_is_allowlisted_with_sensitive_values_redacted(self):
        self.assertIn(INSTALL_SETTINGS_PAGE_PATH, READ_ONLY_PAGE_PATHS)

        source = (
            '<form><input name="settings[pmta_admin_port]" value="1213">'
            '<input name="settings[proxy_port]" value="1214">'
            '<input name="settings[cloud_servers_password]" value="secret-canary">'
            '<select id="dev_ips"><option value="203.0.113.11">203.0.113.11</option></select>'
            '<select id="apply_firewall"><option value="1" selected>YES</option></select>'
            '<textarea>private-config-canary</textarea>'
            '<small>https://private.example/callback</small></form>'
        )

        result = rewrite_document(
            source,
            "text/html",
            f"https://dms.example{INSTALL_SETTINGS_PAGE_PATH}",
        )

        self.assertNotIn("1213", result)
        self.assertNotIn("1214", result)
        self.assertNotIn("secret-canary", result)
        self.assertNotIn("203.0.113.11", result)
        self.assertNotIn("private-config-canary", result)
        self.assertNotIn("private.example", result)
        self.assertIn('id="dev_ips"><option value="synthetic">Example value</option>', result)
        self.assertIn('<option value="0">NO</option><option value="1">YES</option>', result)

    def test_installer_settings_sanitizer_fails_closed_when_expected_fields_disappear(self):
        with self.assertRaises(ValueError):
            sanitize_dms_install_settings_html("<form><input name='unrelated'></form>")

    def test_isp_creation_form_is_an_allowlisted_read_only_reference_page(self):
        self.assertIn(ISPS_CREATE_PAGE_PATH, READ_ONLY_PAGE_PATHS)

    def test_sponsor_create_forms_are_allowlisted_but_sponsor_rows_are_replaced(self):
        self.assertIn(SPONSORS_CREATE_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertIn(SPONSORS_INDEX_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertIn(MY_SPONSORS_PAGE_PATH, READ_ONLY_PAGE_PATHS)

        for path in (SPONSORS_INDEX_PAGE_PATH, MY_SPONSORS_PAGE_PATH):
            self.assertFalse(is_protected_table_data_query(path, ""))
            self.assertTrue(is_protected_table_data_query(path, "draw=1"))
            self.assertTrue(is_protected_table_data_query(path, "", "application/json"))
            self.assertTrue(is_protected_table_data_query(path, "", requested_with="XMLHttpRequest"))

        self.assertFalse(is_protected_table_data_query(SPONSORS_CREATE_PAGE_PATH, "", "application/json"))

    def test_offer_pages_are_allowlisted_and_sponsor_catalog_values_are_redacted(self):
        self.assertIn(OFFERS_INDEX_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertIn(OFFERS_CREATE_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertTrue(is_protected_table_data_query(OFFERS_INDEX_PAGE_PATH, "draw=1&start=0"))
        self.assertTrue(is_protected_table_data_query(OFFERS_INDEX_PAGE_PATH, "", "application/json"))
        self.assertTrue(is_protected_table_data_query(OFFERS_INDEX_PAGE_PATH, "", requested_with="XMLHttpRequest"))
        self.assertFalse(is_protected_table_data_query(OFFERS_INDEX_PAGE_PATH, ""))

        index = rewrite_document(
            '<select id="sponsor_id"><option value="91">Secret sponsor</option></select>'
            '<select id="vertical"><option value="secret-vertical">Secret vertical</option></select>',
            "text/html",
            f"https://dms.example{OFFERS_INDEX_PAGE_PATH}",
        )
        self.assertNotIn("Secret sponsor", index)
        self.assertNotIn("secret-vertical", index)
        self.assertIn("Select Sponsor", index)
        self.assertIn("Select Vertical", index)

        create = rewrite_document(
            '<select id="sponsor" name="sponsor"><option value="92">Private sponsor</option></select>',
            "text/html",
            f"https://dms.example{OFFERS_CREATE_PAGE_PATH}",
        )
        self.assertNotIn("92", create)
        self.assertNotIn("Private sponsor", create)
        self.assertIn("Select Sponsor", create)

    def test_isp_list_html_is_allowlisted_but_catalog_rows_are_replaced(self):
        self.assertIn(ISPS_INDEX_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertFalse(is_isp_catalog_data_request(ISPS_INDEX_PAGE_PATH, ""))
        self.assertTrue(is_isp_catalog_data_request(ISPS_INDEX_PAGE_PATH, "draw=1"))
        self.assertTrue(is_isp_catalog_data_request(ISPS_INDEX_PAGE_PATH, "", "application/json"))
        self.assertTrue(is_isp_catalog_data_request(ISPS_INDEX_PAGE_PATH, "", requested_with="XMLHttpRequest"))
        self.assertFalse(is_isp_catalog_data_request("/admin/isps/create", "", "application/json"))

    def test_provider_list_html_is_allowed_but_provider_rows_are_blocked(self):
        self.assertIn(DOMAIN_PROVIDERS_INDEX_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertFalse(is_protected_table_data_query(DOMAIN_PROVIDERS_INDEX_PAGE_PATH, ""))
        self.assertTrue(is_protected_table_data_query(DOMAIN_PROVIDERS_INDEX_PAGE_PATH, "draw=1"))
        self.assertTrue(is_protected_table_data_query(DOMAIN_PROVIDERS_INDEX_PAGE_PATH, "", "application/json"))
        self.assertTrue(is_protected_table_data_query(DOMAIN_PROVIDERS_INDEX_PAGE_PATH, "", requested_with="XMLHttpRequest"))

    def test_server_provider_pages_are_allowlisted_and_provider_rows_are_blocked(self):
        self.assertIn(SERVERS_PROVIDERS_INDEX_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertIn(SERVERS_PROVIDERS_CREATE_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertFalse(is_protected_table_data_query(SERVERS_PROVIDERS_INDEX_PAGE_PATH, ""))
        self.assertTrue(is_protected_table_data_query(SERVERS_PROVIDERS_INDEX_PAGE_PATH, "draw=1"))
        self.assertTrue(is_protected_table_data_query(SERVERS_PROVIDERS_INDEX_PAGE_PATH, "", "application/json"))
        self.assertTrue(is_protected_table_data_query(SERVERS_PROVIDERS_INDEX_PAGE_PATH, "", requested_with="XMLHttpRequest"))
        self.assertFalse(is_protected_table_data_query(SERVERS_PROVIDERS_CREATE_PAGE_PATH, "draw=1"))

    def test_cloud_provider_index_is_allowlisted_but_live_rows_are_blocked(self):
        self.assertIn(CLOUD_PROVIDERS_INDEX_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertFalse(is_protected_table_data_query(CLOUD_PROVIDERS_INDEX_PAGE_PATH, ""))
        self.assertTrue(is_protected_table_data_query(CLOUD_PROVIDERS_INDEX_PAGE_PATH, "draw=4&start=0"))
        self.assertTrue(is_protected_table_data_query(CLOUD_PROVIDERS_INDEX_PAGE_PATH, "", "application/json"))
        self.assertTrue(is_protected_table_data_query(CLOUD_PROVIDERS_INDEX_PAGE_PATH, "", requested_with="XMLHttpRequest"))
        self.assertNotIn("/admin/cloud-providers/create", READ_ONLY_PAGE_PATHS)

    def test_empty_datatable_response_has_draw_and_zero_counts(self):
        import json

        self.assertEqual(
            json.loads(synthetic_empty_datatable_response("draw=7&start=20")),
            {"draw": 7, "recordsTotal": 0, "recordsFiltered": 0, "data": []},
        )
        self.assertEqual(
            json.loads(synthetic_empty_datatable_response("draw=invalid"))["draw"],
            0,
        )

    def test_server_and_audience_table_queries_are_replaced_with_synthetic_empty_data(self):
        self.assertTrue(is_protected_table_data_query("/admin/servers", "draw=1&start=0"))
        self.assertTrue(is_protected_table_data_query(DOMAINS_INDEX_PAGE_PATH, "only_our=1"))
        self.assertTrue(is_protected_table_data_query(DOMAINS_INDEX_PAGE_PATH, "", "application/json"))
        self.assertTrue(is_protected_table_data_query(DOMAINS_INDEX_PAGE_PATH, "", requested_with="XMLHttpRequest"))
        self.assertFalse(is_protected_table_data_query(DOMAINS_INDEX_PAGE_PATH, ""))
        self.assertTrue(is_protected_table_data_query("/admin/servers", "returned=true"))
        self.assertTrue(is_protected_table_data_query("/admin/lists", "draw=1&start=0"))
        self.assertTrue(is_protected_table_data_query("/admin/lists/0/load", ""))
        self.assertFalse(is_protected_table_data_query("/admin/lists/0/create", ""))
        self.assertEqual(synthetic_empty_list_response(), b'{"data":[]}')
        self.assertTrue(is_protected_table_data_query(BULK_INSTALL_PAGE_PATH, "page=1"))
        self.assertFalse(is_protected_table_data_query(BULK_INSTALL_PAGE_PATH, "synthetic_empty=1"))

    def test_approved_list_pages_without_query_remain_available_for_visual_inspection(self):
        self.assertFalse(is_protected_table_data_query("/admin/servers", ""))
        self.assertIn(DOMAINS_INDEX_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertFalse(is_protected_table_data_query("/admin/lists", ""))
        self.assertFalse(is_protected_table_data_query(BULK_INSTALL_PAGE_PATH, ""))

    def test_other_pages_and_assets_are_not_mistaken_for_reference_table_data(self):
        self.assertFalse(is_protected_table_data_query("/admin/servers/create", "draw=1"))
        self.assertFalse(is_protected_table_data_query("/assets/app.js", "v=1"))

    def test_reference_page_hides_the_production_account_label(self):
        source = "<html><head></head><body><nav id='page-header-user-dropdown'>private label</nav></body></html>"
        result = rewrite_document(source, "text/html", "https://dms.example/admin/servers")
        self.assertIn("#page-header-user-dropdown{display:none!important}", result)

    def test_lists_page_replaces_live_isp_labels_and_rows(self):
        source = (
            '<select id="isps"><option value="private-id">Private ISP</option></select>'
            "<table><tbody><tr><td>private list</td></tr></tbody></table>"
        )
        result = rewrite_document(source, "text/html", "https://dms.example/admin/lists")
        self.assertNotIn("private-id", result)
        self.assertNotIn("Private ISP", result)
        self.assertNotIn("private list", result)
        self.assertIn("Example ISP", result)
        self.assertIn("<tbody></tbody>", result)

    def test_bulk_install_page_replaces_serialized_server_and_domain_rows(self):
        source = (
            '<script>columns:[{ctrlOptions:{"secret-domain-id":"private.example"},'
            'ctrlClass:"domains"}],initData:[{"id":77,"name":"Private server",'
            '"ip":"203.0.113.4"}]});</script>'
        )
        result = rewrite_document(source, "text/html", f"https://dms.example{BULK_INSTALL_PAGE_PATH}")
        self.assertNotIn("secret-domain-id", result)
        self.assertNotIn("private.example", result)
        self.assertNotIn("Private server", result)
        self.assertNotIn("203.0.113.4", result)
        self.assertIn("example.net | STATUS: NEW | IS OUR: NO", result)
        self.assertIn("Example server (synthetic)", result)

    def test_bulk_install_sanitizer_fails_closed_when_data_slots_change(self):
        source = "<script>initData: []</script>"
        with self.assertRaises(ValueError):
            rewrite_document(source, "text/html", f"https://dms.example{BULK_INSTALL_PAGE_PATH}")

    def test_bulk_install_empty_state_removes_serialized_production_servers(self):
        source = (
            '<script>ctrlOptions:{"secret-domain-id":"private.example"},ctrlClass:"domains",'
            'initData:[{"id":77,"name":"Private server","ip":"203.0.113.4"}]});</script>'
        )
        result = rewrite_document(
            source,
            "text/html",
            f"https://dms.example{BULK_INSTALL_PAGE_PATH}",
            empty_bulk_rows=True,
        )
        self.assertIn("initData:[]", result)
        self.assertNotIn("Private server", result)
        self.assertNotIn("private.example", result)

    def test_add_domains_page_replaces_provider_names_and_ids(self):
        source = (
            '<select id="provider" name="provider">'
            '<option value="private-id">Private provider</option></select>'
            '<div id="import_div">IMPORT</div>'
        )
        result = rewrite_document(source, "text/html", "https://dms.example/admin/domains/create")

        self.assertNotIn("private-id", result)
        self.assertNotIn("Private provider", result)
        self.assertIn("Example provider (synthetic)", result)
        self.assertIn('id="provider"', result)
        self.assertIn("IMPORT", result)

    def test_domains_create_route_is_rewritten_for_the_parent_modal(self):
        source = f"const link = '{REFERENCE_ORIGIN}/admin/domains/create';"
        result = rewrite_document(source, "text/html", f"{REFERENCE_ORIGIN}/admin/domains")

        self.assertIn("const link = 'domains/create';", result)
        self.assertNotIn("dms.example", result)


if __name__ == "__main__":
    unittest.main()
