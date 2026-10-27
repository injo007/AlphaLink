from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from catalog_dms_routes import _route_calls, catalog_file


class DmsRouteCatalogTest(unittest.TestCase):
    def test_nested_route_prefixes_methods_and_names_are_captured(self) -> None:
        source = """<?php
// Route::delete('/commented', [Fake::class, 'nope']);
Route::group(['prefix' => 'servers', 'as' => 'servers.'], function () {
    Route::get('{server}/edit', [ServerController::class, 'edit'])->name('edit');
    Route::match(['get', 'post'], '{server}/records', [ServerController::class, 'records'])->name('records');
});
"""
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            path = root / "routes" / "admin.php"
            path.parent.mkdir()
            path.write_text(source)
            records, counts = catalog_file(path, root)

        self.assertEqual(counts, {"group": 1, "get": 1, "match": 1})
        self.assertEqual([route["path_template"] for route in records], ["/admin/servers/:server/edit", "/admin/servers/:server/records"])
        self.assertEqual([route["route_name"] for route in records], ["servers.edit", "servers.records"])
        self.assertEqual([route["controller"] for route in records], ["ServerController", "ServerController"])

    def test_string_arguments_and_comments_do_not_create_route_calls(self) -> None:
        source = """<?php
Route::get('/mailers/{id}', static function () { return 'Route::delete fake'; });
/* Route::post('/commented'); */
"""
        calls = _route_calls(source)
        self.assertEqual([call.method for call in calls], ["get"])

    def test_prefix_wrapper_and_fluent_route_name_are_included(self) -> None:
        source = """<?php
Route::prefix('v1')->name('.v1.')->group(function () {
    Route::post('subscribe', [ApiController::class, 'subscribe'])->name('subscribe');
});
"""
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            path = root / "routes" / "api.php"
            path.parent.mkdir()
            path.write_text(source)
            records, counts = catalog_file(path, root)

        self.assertEqual(counts, {"prefix": 1, "post": 1})
        self.assertEqual(records[0]["http_method"], "POST")
        self.assertEqual(records[0]["path_template"], "/api/v1/subscribe")
        self.assertEqual(records[0]["route_name"], "v1.subscribe")
        self.assertEqual(records[0]["controller"], "ApiController")
        self.assertEqual(records[0]["action"], "subscribe")


if __name__ == "__main__":
    unittest.main()
