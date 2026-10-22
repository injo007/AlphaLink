import unittest

from audit.tools.dms_reference_readonly_relay import is_server_list_data_query, rewrite_document


class ServerListPrivacyTests(unittest.TestCase):
    def test_server_table_queries_are_replaced_with_synthetic_empty_data(self):
        self.assertTrue(is_server_list_data_query("/admin/servers", "draw=1&start=0"))
        self.assertTrue(is_server_list_data_query("/admin/servers", "returned=true"))

    def test_server_page_without_query_remains_available_for_visual_inspection(self):
        self.assertFalse(is_server_list_data_query("/admin/servers", ""))

    def test_other_pages_and_assets_are_not_mistaken_for_server_data(self):
        self.assertFalse(is_server_list_data_query("/admin/servers/create", "draw=1"))
        self.assertFalse(is_server_list_data_query("/assets/app.js", "v=1"))

    def test_reference_page_hides_the_production_account_label(self):
        source = "<html><head></head><body><nav id='page-header-user-dropdown'>private label</nav></body></html>"
        result = rewrite_document(source, "text/html", "https://dms.example/admin/servers")
        self.assertIn("#page-header-user-dropdown{display:none!important}", result)


if __name__ == "__main__":
    unittest.main()
