import unittest

from audit.tools.dms_reference_readonly_relay import (
    is_protected_table_data_query,
    rewrite_document,
)


class ReferenceListPrivacyTests(unittest.TestCase):
    def test_server_and_audience_table_queries_are_replaced_with_synthetic_empty_data(self):
        self.assertTrue(is_protected_table_data_query("/admin/servers", "draw=1&start=0"))
        self.assertTrue(is_protected_table_data_query("/admin/servers", "returned=true"))
        self.assertTrue(is_protected_table_data_query("/admin/lists", "draw=1&start=0"))

    def test_approved_list_pages_without_query_remain_available_for_visual_inspection(self):
        self.assertFalse(is_protected_table_data_query("/admin/servers", ""))
        self.assertFalse(is_protected_table_data_query("/admin/lists", ""))

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


if __name__ == "__main__":
    unittest.main()
