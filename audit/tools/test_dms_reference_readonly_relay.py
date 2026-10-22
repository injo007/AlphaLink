import unittest

from audit.tools.dms_reference_readonly_relay import (
    BULK_INSTALL_PAGE_PATH,
    is_protected_table_data_query,
    rewrite_document,
)


class ReferenceListPrivacyTests(unittest.TestCase):
    def test_server_and_audience_table_queries_are_replaced_with_synthetic_empty_data(self):
        self.assertTrue(is_protected_table_data_query("/admin/servers", "draw=1&start=0"))
        self.assertTrue(is_protected_table_data_query("/admin/servers", "returned=true"))
        self.assertTrue(is_protected_table_data_query("/admin/lists", "draw=1&start=0"))
        self.assertTrue(is_protected_table_data_query(BULK_INSTALL_PAGE_PATH, "page=1"))
        self.assertFalse(is_protected_table_data_query(BULK_INSTALL_PAGE_PATH, "synthetic_empty=1"))

    def test_approved_list_pages_without_query_remain_available_for_visual_inspection(self):
        self.assertFalse(is_protected_table_data_query("/admin/servers", ""))
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


if __name__ == "__main__":
    unittest.main()
