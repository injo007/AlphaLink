import unittest

from audit.tools.dms_reference_readonly_relay import (
    BULK_INSTALL_PAGE_PATH,
    CAMPAIGNS_CREATE_PAGE_PATH,
    CAMPAIGNS_INDEX_PAGE_PATH,
    CAMPAIGN_LOGS_PAGE_PATH,
    MAILER_SERVERS_INDEX_PAGE_PATH,
    CLOUD_PROVIDERS_INDEX_PAGE_PATH,
    DOMAINS_INDEX_PAGE_PATH,
    DOMAIN_PROVIDERS_INDEX_PAGE_PATH,
    ENCODING_PAGE_PATH,
    ENCODING_POPUP_PAGE_PATH,
ENCODING_PAGE_PATHS,
SPF_DOMAINS_PAGE_PATH,
SPF_PAGE_PATHS,
SPF_SCANNER_PAGE_PATH,
    GLOBAL_TESTS_PAGE_PATH,
    GLOBAL_TEST_HISTORIES_PAGE_PATH,
    GMAIL_PROJECTS_PAGE_PATH,
    GMAIL_PROJECTS_CREATE_PAGE_PATH,
    GMAIL_ACCOUNTS_PAGE_PATH,
    GMAIL_ACCOUNTS_CREATE_PAGE_PATH,
    GMAIL_WORKSPACE_PAGE_PATHS,
    WORKSPACE_SMTP_PAGE_PATH,
    DROP_SUPPRESSION_PAGE_PATH,
    TEXT_MECHANIC_PAGE_PATH,
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
    is_read_only_page_path,
    REFERENCE_ORIGIN,
    is_protected_table_data_query,
    local_path_for,
    is_protected_pmta_data_request,
    is_isp_catalog_data_request,
    rewrite_document,
    sanitize_dms_install_settings_html,
    sanitize_dms_campaign_create_html,
    sanitize_dms_campaign_logs_html,
    sanitize_dms_profile_html,
    sanitize_dms_pmta_html,
    is_protected_campaign_catalog_request,
    is_protected_global_test_data_request,
    sanitize_dms_global_tests_html,
    sanitize_dms_gmail_workspace_html,
    sanitize_dms_offer_suppression_html,
    is_read_only_offer_suppression_page,
    sanitize_dms_drop_suppression_html,
    is_protected_spf_data_request,
    sanitize_dms_spf_html,
    synthetic_empty_spf_response,
    sanitize_dms_text_mechanic_html,
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
    def test_drops_suppression_page_is_read_only_and_sanitized(self):
        self.assertIn(DROP_SUPPRESSION_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertTrue(is_read_only_page_path(DROP_SUPPRESSION_PAGE_PATH))
        self.assertFalse(is_read_only_page_path(DROP_SUPPRESSION_PAGE_PATH + "/monitor/123"))
        self.assertFalse(is_read_only_page_path(DROP_SUPPRESSION_PAGE_PATH + "/run"))

        source = (
            '<html><head><title>Drops Suppression</title>'
            '<meta name="csrf-token" content="private-csrf"></head><body>'
            '<nav id="page-header-user-dropdown">private operator@example.test</nav>'
            '<h1>SUPPRESSION</h1><label>Sponsor</label>'
            '<form id="suppression-form" class="row" method="post" action="/mailer/drops/suppression/run" onsubmit="privateRun()">'
            '<input type="hidden" name="_token" value="private-csrf">'
            '<select id="offer"><option value="private-offer-id">private-offer@example.test</option></select>'
            '<textarea>/var/lib/private/suppression.csv</textarea>'
            '<button type="submit">RUN SUPPRESSION</button></form>'
            '<table><thead><tr><th>Offer</th><th>Found</th></tr></thead>'
            '<tbody><tr><td>private-offer@example.test</td><td>282339</td></tr></tbody></table>'
            '<script>const token="private-csrf"; const offer_id=12345; startSuppression("/mailer/drops/start-suppression"); const email="private@example.test";</script>'
            '<script src="/assets/drops-suppression.js?v=private-query"></script></body></html>'
        )

        result = rewrite_document(source, "text/html", f"{REFERENCE_ORIGIN}{DROP_SUPPRESSION_PAGE_PATH}")

        for private_value in (
            "private-csrf", "private operator@example.test", "private-offer-id",
            "private-offer@example.test", "/var/lib/private/suppression.csv", "282339",
            "private-csrf", "private-query", "/run", "12345", "private@example.test",
        ):
            self.assertNotIn(private_value, result)
        self.assertIn("SUPPRESSION", result)
        self.assertIn("RUN SUPPRESSION", result)
        self.assertIn('id="offer"', result)
        self.assertNotRegex(result, r"<script\b|<form\b")
        self.assertIn('<template data-reference-script-path="/assets/drops-suppression.js"></template>', result)
        self.assertIn('data-reference-inline-script="1"', result)
        self.assertIn("startSuppression", result)
        with self.assertRaises(ValueError):
            sanitize_dms_drop_suppression_html("<main>Unexpected response</main>", DROP_SUPPRESSION_PAGE_PATH)

    def test_workspace_smtp_page_is_allowlisted_and_sanitized_without_live_values_or_scripts(self):
        self.assertIn(WORKSPACE_SMTP_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        source = (
            '<html><head><title>Workspace SMTP - DMS</title>'
            '<meta name="csrf-token" content="private-csrf"></head><body>'
            '<nav id="page-header-user-dropdown">private operator@example.test</nav>'
            '<h1>PUSH WORKSPACE ACCOUNTS</h1><label>IPs:</label>'
            '<form action="/mailer/tools/workspace/push" onsubmit="privatePush()">'
            '<select id="ips"><option value="server-private-id">203.0.113.24</option></select>'
            '<select id="project"><option value="project-private-id">private-project@example.test</option></select>'
            '<input id="split_on_ips" type="checkbox" checked>'
            '<button id="push">PUSH</button><button id="reset">RESET</button></form>'
            '<table><thead><tr><th>SERVER</th><th>RESULT</th></tr></thead>'
            '<tbody><tr><td>server-private-id</td><td>Workspace Pushed Successfully</td></tr></tbody></table>'
            '<script>const server_id="server-private-id"; const api_key="private-workspace-secret"; const email="private-account@example.test"; function privatePush(){ fetch("/mailer/tools/workspace/push", {method:"POST"}); }</script>'
            '<script src="/assets/workspace.js?v=private-query"></script></body></html>'
        )

        result = rewrite_document(source, "text/html", f"{REFERENCE_ORIGIN}{WORKSPACE_SMTP_PAGE_PATH}")

        for private_value in (
            "private-csrf", "private operator@example.test", "server-private-id", "203.0.113.24",
            "private-project@example.test", "Workspace Pushed Successfully", "private-account@example.test",
            "private-workspace-secret", "private-query",
        ):
            self.assertNotIn(private_value, result)
        self.assertIn("PUSH WORKSPACE ACCOUNTS", result)
        self.assertIn('id="ips"', result)
        self.assertIn('id="project"', result)
        self.assertEqual(result.count("Values withheld"), 2)
        self.assertIn('id="push"', result)
        self.assertIn('id="reset"', result)
        self.assertNotRegex(result, r"<script\b")
        self.assertIn('<template data-reference-script-path="/assets/workspace.js"></template>', result)
        self.assertIn('data-reference-inline-script="1"', result)
        self.assertIn("/mailer/tools/workspace/push", result)
        self.assertIn("privatePush", result)

    def test_offer_suppression_page_read_only_path_and_sanitizer_reject_action_routes(self):
        path = "/admin/offers/123456/suppression"
        current_path = "/offers/123456/suppression"
        self.assertTrue(is_read_only_offer_suppression_page(path))
        self.assertTrue(is_read_only_page_path(path))
        self.assertTrue(is_read_only_offer_suppression_page(current_path))
        self.assertTrue(is_read_only_page_path(current_path))
        self.assertFalse(is_read_only_page_path(path + "/download"))
        self.assertFalse(is_read_only_page_path(path + "/refresh"))
        self.assertFalse(is_read_only_page_path(current_path + "/download"))
        self.assertFalse(is_read_only_page_path(current_path + "/progress"))

        source = (
            '<html><head><title>Offer Suppression File</title>'
            '<meta name="csrf-token" content="private-csrf"></head><body>'
            '<nav id="page-header-user-dropdown">private operator@example.test</nav>'
            '<h1>SUPPRESSION FILE</h1><div>Private sponsor endpoint https://sponsor.invalid/file?token=private-token</div>'
            '<a href="/offers/123456/suppression">Open suppression manager</a>'
            '<form action="/admin/offers/123456/suppression/download" onsubmit="privateDownload()">'
            '<input type="hidden" name="_token" value="private-csrf">'
            '<select><option value="private-offer">private-offer@example.test</option></select>'
            '<textarea>/var/lib/private/suppression.csv</textarea><button>DOWNLOAD</button></form>'
            '<table><thead><tr><th>Offer</th><th>Entries</th></tr></thead>'
            '<tbody><tr><td>private-offer@example.test</td><td>282339</td></tr></tbody></table>'
            '<script>const file="/var/lib/private/suppression.csv"; const email="private@example.test";</script>'
            '<script src="/assets/offer-suppression.js?v=private-query"></script></body></html>'
        )

        result = rewrite_document(source, "text/html", f"{REFERENCE_ORIGIN}{path}")

        for private_value in (
            "123456", "private-csrf", "private operator@example.test", "sponsor.invalid",
            "private-token", "private-offer@example.test", "/var/lib/private/suppression.csv",
            "282339", "privateDownload", "private@example.test", "private-query",
        ):
            self.assertNotIn(private_value, result)
        self.assertIn("SUPPRESSION FILE", result)
        self.assertIn("DOWNLOAD", result)
        self.assertNotRegex(result, r"<script\b")
        self.assertIn('<template data-reference-script-path="/assets/offer-suppression.js"></template>', result)
        with self.assertRaises(ValueError):
            sanitize_dms_offer_suppression_html("<main>Unexpected response</main>", path)

    def test_gmail_workspace_pages_are_allowlisted_and_rows_are_blocked_or_sanitized(self):
        self.assertEqual(GMAIL_WORKSPACE_PAGE_PATHS, {
            GMAIL_PROJECTS_PAGE_PATH,
            GMAIL_PROJECTS_CREATE_PAGE_PATH,
            GMAIL_ACCOUNTS_PAGE_PATH,
            GMAIL_ACCOUNTS_CREATE_PAGE_PATH,
        })
        self.assertTrue(GMAIL_WORKSPACE_PAGE_PATHS.issubset(READ_ONLY_PAGE_PATHS))
        for path in {GMAIL_PROJECTS_PAGE_PATH, GMAIL_ACCOUNTS_PAGE_PATH}:
            self.assertFalse(is_protected_table_data_query(path, ""))
            self.assertTrue(is_protected_table_data_query(path, "draw=1&start=0"))
            self.assertTrue(is_protected_table_data_query(path, "", "application/json"))
            self.assertTrue(is_protected_table_data_query(path, "", requested_with="XMLHttpRequest"))
        for path in {GMAIL_PROJECTS_CREATE_PAGE_PATH, GMAIL_ACCOUNTS_CREATE_PAGE_PATH}:
            self.assertFalse(is_protected_table_data_query(path, ""))
            self.assertFalse(is_protected_table_data_query(path, "?return=private"))

        source = (
            '<html><head><title>Gmail Projects</title>'
            '<meta name="csrf-token" content="private-csrf"></head><body>'
            '<nav id="page-header-user-dropdown">private operator@example.com</nav>'
            '<h1>GMAIL PROJECTS</h1><button>ADD PROJECT</button>'
            '<form action="/mailer/gcampaigns/projects" onsubmit="privateSubmit()">'
            '<input type="hidden" name="_token" value="private-token">'
            '<input id="admin" value="owner@example.com">'
            '<select><option value="project-91">private-project@example.com</option></select>'
            '<textarea>private service account JSON</textarea></form>'
            '<table><thead><tr><th>Project</th><th>Status</th></tr></thead>'
            '<tbody><tr><td>project-91</td><td>secret-tenant-name</td></tr></tbody></table>'
            '<script>const secret="inline-private-script"; const config={project_id:91, email:"inside@example.com", service_account_json:"private-key-material"}; fetch("/mailer/gcampaigns/projects/91/sync");</script>'
            '<script src="/assets/gcampaign-projects.js?v=private-query"></script>'
            '</body></html>'
        )

        result = rewrite_document(source, "text/html", f"{REFERENCE_ORIGIN}{GMAIL_PROJECTS_PAGE_PATH}")

        for private_value in (
            "private-csrf", "private-token", "owner@example.com", "private-project@example.com",
            "private service account JSON", "project-91", "secret-tenant-name", "inline-private-script",
            "private-query", "privateSubmit", "private operator@example.com", "inside@example.com",
            "private-key-material",
        ):
            self.assertNotIn(private_value, result)
        self.assertIn("GMAIL PROJECTS", result)
        self.assertIn("ADD PROJECT", result)
        self.assertIn("Project</th>", result)
        self.assertNotRegex(result, r"<script\b")
        self.assertIn('<template data-reference-script-path="/assets/gcampaign-projects.js"></template>', result)
        self.assertIn('data-reference-inline-script="1"', result)
        self.assertIn("fetch(&quot;/mailer/gcampaigns/projects/[redacted]/sync&quot;)", result)
        self.assertNotIn("projects/91/sync", result)

    def test_gmail_create_pages_keep_only_sanitized_form_structure(self):
        examples = {
            GMAIL_PROJECTS_CREATE_PAGE_PATH: "Create Gmail Project",
            GMAIL_ACCOUNTS_CREATE_PAGE_PATH: "Create Gmail Account",
        }
        for path, title in examples.items():
            with self.subTest(path=path):
                source = (
                    f"<html><head><title>{title}</title><meta name='csrf-token' content='secret-token'></head><body>"
                    '<form action="/mailer/gcampaigns/private-action" onsubmit="submitPrivate()">'
                    '<input id="s3" type="checkbox"><label>S3</label>'
                    '<label>Delegated email</label><input id="name" name="name" value="private-project-name">'
                    '<label>Credentials</label><textarea>private credential material</textarea>'
                    '<input id="client_id" name="client_id" value="private-client-id">'
                    '<input id="client_secret" name="client_secret" value="private-client-secret">'
                    '<textarea id="secret_admin" name="secret_admin">private admin credential</textarea>'
                    '<label>Allowed Mailers : CHECK</label>'
                    '<div class="custom-control custom-checkbox"><input type="checkbox" name="checked_users" value="user-91">'
                    '<label>private-user-label</label></div>'
                    '<select id="api_type" name="api_type"><option value="users">users</option>'
                    '<option value="workspace">workspace</option>'
                    '<option value="project-91">private-project-name</option></select>'
                    '<select><option value="private-option">Private option</option></select>'
                    '<button id="check">CHECK</button><button id="save">SAVE</button>'
                    '<input id="link" name="link" value="private-link"><button id="sync">SYNC</button>'
                    '<input type="hidden" name="_token" value="secret-token"></form>'
                    '<script>const email="private@example.test"; const token="private-token"; '
                    'const checked_users=[{id:91,username:"private-user-label"}];</script>'
                    '</body></html>'
                )
                result = rewrite_document(source, "text/html", f"{REFERENCE_ORIGIN}{path}")

                self.assertIn(title, result)
                self.assertIn("Delegated email", result)
                self.assertIn("Credentials", result)
                self.assertIn("Allowed Mailers : CHECK", result)
                if path == GMAIL_PROJECTS_CREATE_PAGE_PATH:
                    self.assertIn("<option>Users</option>", result)
                    self.assertIn("<option>Workspace</option>", result)
                    for control_id in ("s3", "api_type", "name", "client_id", "client_secret", "secret_admin", "check", "save", "link", "sync"):
                        self.assertIn(f'id="{control_id}"', result)
                else:
                    self.assertNotIn("<option>", result)
                for private_value in (
                    "private@example.test", "private credential material", "Private option", "project-91",
                    "secret-token", "private-token", "private-action", "submitPrivate",
                    "private-project-name", "checked_users", "user-91", "private-user-label",
                ):
                    self.assertNotIn(private_value, result)
                self.assertIn('name="name"', result)
                self.assertNotRegex(result, r"<script\b")
                self.assertNotIn("data-reference-inline-script", result)

    def test_gmail_create_sanitizer_fails_closed_for_uncontained_user_controls(self):
        source = (
            '<html><title>Create Gmail Project</title><form>'
            '<input type="checkbox" name="checked_users" value="private-user">'
            '</form></html>'
        )
        with self.assertRaisesRegex(ValueError, "private checked_users control"):
            sanitize_dms_gmail_workspace_html(source, GMAIL_PROJECTS_CREATE_PAGE_PATH)

    def test_gmail_workspace_sanitizer_fails_closed_for_an_unexpected_page(self):
        with self.assertRaises(ValueError):
            sanitize_dms_gmail_workspace_html("<main>Unexpected page</main>", GMAIL_PROJECTS_PAGE_PATH)

    def test_encoding_get_pages_are_allowlisted_without_the_post_action(self):
        self.assertEqual(ENCODING_PAGE_PATHS, {ENCODING_PAGE_PATH, ENCODING_POPUP_PAGE_PATH})
        self.assertTrue(ENCODING_PAGE_PATHS.issubset(READ_ONLY_PAGE_PATHS))
        self.assertNotIn('/mailer/tools/encoding/encode', READ_ONLY_PAGE_PATHS)

    def test_text_mechanic_page_is_allowlisted_and_sanitized_as_static_evidence(self):
        self.assertIn(TEXT_MECHANIC_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        source = (
            '<html><head><title>Text Mechanic</title>'
            '<meta name="csrf-token" content="private-csrf-meta">'
            '<meta name="csrf-param" content="private-csrf-param"></head><body>'
            '<nav id="page-header-user-dropdown"><span>private account</span></nav>'
            '<h1>TEXT MECHANIC</h1><label for="text">Text</label>'
            '<p>2001:db8::1</p>'
            '<input id="token" type="hidden" value="private-token">'
            '<input id="text" value="private@example.net">'
            '<textarea id="source">private message body</textarea>'
            '<select id="mode"><option value="private">private option</option></select>'
            '<button onclick="privateAction()">SORT</button>'
            '<button id="copy" data-clipboard-target="#text">COPY</button>'
            '<table><tr><th>Output</th></tr><tr><td>private@example.net</td></tr></table>'
            "<script>function sortLines(){return document.getElementById('text').value.split('\\n').sort().join('\\n');}"
            "const csrfToken='private-csrf'; const url='/check?token=private-query'; const ip6='2001:db8::1';"
            "// private@example.net privateInlineBehavior</script>"
            '<script src="/assets/text-mechanic.js"></script>'
            '<script src="https://untrusted.example/private.js"></script>'
            '</body></html>'
        )

        result = rewrite_document(
            source,
            "text/html",
            f"{REFERENCE_ORIGIN}{TEXT_MECHANIC_PAGE_PATH}",
        )

        for private_value in (
            "private account",
            "private-token",
            "private@example.net",
            "private message body",
            "private option",
            "privateAction",
            "privateInlineBehavior",
            "2001:db8::1",
            "private-csrf",
            "private-query",
            "private-csrf-meta",
            "private-csrf-param",
            "untrusted.example",
        ):
            self.assertNotIn(private_value, result)
        self.assertIn("Text Mechanic", result)
        self.assertIn("SORT", result)
        self.assertIn('data-clipboard-target="#text"', result)
        self.assertIn("<textarea id=\"source\"></textarea>", result)
        self.assertNotRegex(result, r"<script\b")
        self.assertIn('<template data-reference-script-path="/assets/text-mechanic.js"></template>', result)
        self.assertIn('data-reference-inline-script="1"', result)
        self.assertIn("sortLines", result)

    def test_text_mechanic_sanitizer_fails_closed_for_unexpected_page(self):
        with self.assertRaises(ValueError):
            sanitize_dms_text_mechanic_html(
                "<html><body><h1>Unexpected page</h1></body></html>",
                f"{REFERENCE_ORIGIN}{TEXT_MECHANIC_PAGE_PATH}",
            )

    def test_spf_pages_are_allowlisted_but_all_saved_search_data_is_blocked(self):
        self.assertEqual(SPF_PAGE_PATHS, {SPF_SCANNER_PAGE_PATH, SPF_DOMAINS_PAGE_PATH})
        self.assertTrue(SPF_PAGE_PATHS.issubset(READ_ONLY_PAGE_PATHS))
        self.assertTrue(is_protected_spf_data_request(SPF_SCANNER_PAGE_PATH, "ips=198.51.100.7"))
        self.assertTrue(is_protected_spf_data_request(SPF_DOMAINS_PAGE_PATH, "search=private"))
        self.assertTrue(is_protected_spf_data_request(f"{SPF_SCANNER_PAGE_PATH}/records", "id=91"))
        self.assertFalse(is_protected_spf_data_request(SPF_SCANNER_PAGE_PATH, ""))
        self.assertFalse(is_protected_spf_data_request(SPF_DOMAINS_PAGE_PATH, ""))
        self.assertNotIn(f"{SPF_SCANNER_PAGE_PATH}/search", READ_ONLY_PAGE_PATHS)
        self.assertEqual(
            synthetic_empty_spf_response("draw=3&start=0"),
            b'{"draw": 3, "recordsTotal": 0, "recordsFiltered": 0, "data": []}',
        )
        self.assertEqual(synthetic_empty_spf_response("id=91"), b'{"data": []}')

    def test_spf_page_capture_removes_saved_values_rows_tokens_and_scripts(self):
        source = (
            '<html><head><title>SPF Scanner - DMS</title>'
            '<meta name="csrf-token" content="private-csrf"></head><body>'
            '<nav id="page-header-user-dropdown">private-account</nav>'
            '<h1>SPF Scanner</h1><span>ROOTS 3,400 HOSTS 6,700 CIDRS 8</span>'
            '<p>Resolver example: 2001:db8::42</p>'
            '<p>Loopback example: ::1</p>'
            '<p>IPv4-mapped example: ::ffff:192.0.2.128</p>'
            '<form action="/mailer/tools/spf-scanner/search">'
            '<input id="ip" value="198.51.100.7"><input type="hidden" value="private-token">'
            '<textarea id="domains">private.example.net</textarea>'
            '<select id="server"><option value="server-secret">private.example.net</option></select>'
            '<button onclick="submitPrivateSearch()" data-domain="private.example.net">SEARCH</button>'
            '<table><tr><th>Domain</th><th>SPF</th></tr>'
            '<tr><td>private.example.net</td><td>v=spf1 ip4:198.51.100.7 -all</td></tr></table>'
            '<pre>v=spf1 ip4:198.51.100.7 -all</pre>'
            '<script src="/assets/spf-scanner.js?cache-buster=private"></script>'
            '<script src="//external.example/private.js"></script>'
            '<script>const privateDomain = "private.example.net";</script></body></html>'
        )

        result = rewrite_document(source, "text/html", f"{REFERENCE_ORIGIN}{SPF_SCANNER_PAGE_PATH}")

        for private_value in (
            "private-csrf", "private-account", "private-token", "private.example.net",
            "198.51.100.7", "server-secret", "submitPrivateSearch", "privateDomain",
            "2001:db8::42", "::1", "::ffff:192.0.2.128", "192.0.2.128",
            "v=spf1", "3,400", "6,700", "cache-buster=private", "external.example",
        ):
            self.assertNotIn(private_value, result)
        self.assertIn("SPF Scanner", result)
        self.assertIn("SEARCH", result)
        self.assertIn("[redacted-ipv6]", result)
        self.assertIn('<template data-reference-script-path="/assets/spf-scanner.js"></template>', result)
        self.assertNotRegex(result, r"<script\b|action=|on[a-z]+=|data-domain=")
        self.assertIn('<textarea id="domains"></textarea>', result)
        self.assertIn('<table><tr><th>Domain</th><th>SPF</th></tr></table>', result)

    def test_spf_sanitizer_fails_closed_for_unexpected_page_or_path(self):
        with self.assertRaises(ValueError):
            sanitize_dms_spf_html("<h1>Unexpected page</h1>", SPF_SCANNER_PAGE_PATH)
        with self.assertRaises(ValueError):
            sanitize_dms_spf_html("<h1>SPF Domains</h1>", "/mailer/tools/spf-scanner/export")

    def test_spf_domains_capture_redacts_live_pagination_and_record_totals(self):
        source = (
            '<html><title>Spf Domains - DMS</title><h1>SCANNED SPF ROOTS</h1>'
            '<div>Page <span>1</span> of <span>960000</span> (<b>24,000,000</b> records)</div>'
            '<ul class="pagination"><li><a href="?page=959999">959999</a></li></ul>'
            '<table><tr><th>DOMAIN</th><th>STATUS</th></tr>'
            '<tr><td>private.example.net</td><td>OK</td></tr></table></html>'
        )

        result = rewrite_document(source, "text/html", f"{REFERENCE_ORIGIN}{SPF_DOMAINS_PAGE_PATH}")

        for private_value in ("960000", "24,000,000", "959999", "private.example.net", "page=959999"):
            self.assertNotIn(private_value, result)
        self.assertIn("Page 1 of 1 (0 records)", result)
        self.assertIn('<table><tr><th>DOMAIN</th><th>STATUS</th></tr></table>', result)

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

    def test_campaign_logs_page_is_allowlisted_and_all_history_rows_are_blocked(self):
        self.assertIn(CAMPAIGN_LOGS_PAGE_PATH, READ_ONLY_PAGE_PATHS)
        self.assertFalse(is_protected_table_data_query(CAMPAIGN_LOGS_PAGE_PATH, ""))
        self.assertTrue(is_protected_table_data_query(CAMPAIGN_LOGS_PAGE_PATH, "draw=1&start=0"))
        self.assertTrue(is_protected_table_data_query(CAMPAIGN_LOGS_PAGE_PATH, "", "application/json"))
        self.assertTrue(is_protected_table_data_query(CAMPAIGN_LOGS_PAGE_PATH, "", requested_with="XMLHttpRequest"))
        self.assertNotIn(f"{CAMPAIGN_LOGS_PAGE_PATH}/private-campaign/tests", READ_ONLY_PAGE_PATHS)

        source = (
            '<html><head><title>Campaign Logs - DMS</title><meta name="csrf-token" content="private-csrf">'
            '<script src="/assets/campaign-logs.js?secret=private-query"></script>'
            '<script>const privateCampaign="campaign-name"; const email="owner@example.test";</script></head><body>'
            '<nav id="page-header-user-dropdown">private operator@example.test</nav>'
            '<h1>CAMPAIGN LOGS</h1><form action="/mailer/campaign-logs/private-action">'
            '<input type="hidden" name="_token" value="private-token"><input name="search" value="private name">'
            '<select><option value="private-campaign-id">private campaign label</option></select></form>'
            '<table><thead><tr><th>Campaign</th><th>Type</th><th>Action</th></tr></thead>'
            '<tbody><tr><td>private campaign title</td><td>Test</td><td><a href="/mailer/campaign-logs/private-id/tests">OPEN</a>'
            '<span>owner@example.test 198.51.100.7 2001:db8::1 12345678-1234-4234-8234-123456789abc</span></td></tr></tbody></table>'
            '<div>Showing 1 to 20 of 125 records</div></body></html>'
        )

        result = sanitize_dms_campaign_logs_html(source)

        for private_value in (
            "private-csrf", "private-token", "private name", "private campaign label", "private campaign title",
            "privateCampaign", "owner@example.test", "198.51.100.7", "2001:db8::1", "12345678-1234-4234-8234-123456789abc",
            "private-id", "private-query", "private operator@example.test",
        ):
            self.assertNotIn(private_value, result)
        self.assertIn("CAMPAIGN LOGS", result)
        self.assertIn("<th>Campaign</th>", result)
        self.assertIn("Showing 0 to 0 of 0 records", result)
        self.assertIn('<template data-reference-script-path="/assets/campaign-logs.js"></template>', result)
        self.assertNotRegex(result, r"<script\\b|action=|on[a-z]+=|data-[\\w:-]+=|href=\"/mailer/campaign-logs/")

    def test_campaign_logs_sanitizer_fails_closed_for_unexpected_page(self):
        with self.assertRaisesRegex(ValueError, "expected table page"):
            sanitize_dms_campaign_logs_html('<html><title>Campaign Settings</title><table></table></html>')

    def test_campaign_logs_document_rewrite_uses_the_fail_closed_sanitizer(self):
        source = (
            '<html><head><title>Campaign Logs - DMS</title></head><body>'
            '<h1>CAMPAIGN LOGS</h1><table id="logs"><thead><tr><th>Type</th></tr></thead>'
            '<tbody><tr><td>private campaign row</td></tr></tbody></table>'
            '<script>const privateValue="do-not-forward";</script></body></html>'
        )

        result = rewrite_document(source, "text/html", f"{REFERENCE_ORIGIN}{CAMPAIGN_LOGS_PAGE_PATH}")

        self.assertIn("CAMPAIGN LOGS", result)
        self.assertIn("<th>Type</th>", result)
        self.assertNotIn("private campaign row", result)
        self.assertNotIn("do-not-forward", result)

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
