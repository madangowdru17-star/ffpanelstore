"""Standalone HEX CHEATS SHOP website server."""
from __future__ import annotations

import os
from pathlib import Path

from aiohttp import web

from website_core import BASE_DIR, initialize_database, login


async def admin_login_page(request: web.Request) -> web.Response:
    return web.FileResponse(BASE_DIR / "admin_login.html", headers={"Cache-Control": "no-store"})


async def catalog_page(request: web.Request) -> web.Response:
    return web.FileResponse(BASE_DIR / "catalog_admin.html", headers={"Cache-Control": "no-store"})


def create_app() -> web.Application:
    app = web.Application(client_max_size=6 * 1024 * 1024)
    initialize_database()

    from catalog_admin import (
        product_create,
        product_delete,
        product_image_delete,
        product_image_upload,
        product_list,
        product_update,
    )
    from storefront import register_routes

    app.router.add_get("/admin", admin_login_page)
    app.router.add_get("/admin/", admin_login_page)
    app.router.add_get("/admin/catalog", catalog_page)
    app.router.add_post("/admin/api/login", login)
    app.router.add_get("/admin/api/products", product_list)
    app.router.add_post("/admin/api/products", product_create)
    app.router.add_put("/admin/api/products/{key}", product_update)
    app.router.add_delete("/admin/api/products/{key}", product_delete)
    app.router.add_post("/admin/api/products/{key}/image", product_image_upload)
    app.router.add_delete("/admin/api/products/{key}/image", product_image_delete)
    register_routes(app)
    return app


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "8080"))
    web.run_app(create_app(), host="0.0.0.0", port=port, print=None)
