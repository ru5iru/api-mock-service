#!/usr/bin/env python3
"""Read-only HTTP/HTTPS smoke checks against the same external port."""

import concurrent.futures
import os
import ssl
import urllib.error
import urllib.parse
import urllib.request


def main():
    origin = urllib.parse.urlsplit(
        os.environ.get("MOCKDECK_SMOKE_ORIGIN", "http://localhost:18473")
    )
    if origin.scheme not in ("http", "https") or not origin.hostname or not origin.port:
        raise ValueError("MOCKDECK_SMOKE_ORIGIN must specify http(s), host and port")
    if origin.username or origin.password or origin.path not in ("", "/"):
        raise ValueError("Use an origin without credentials or a path")
    context = ssl.create_default_context(cafile=os.environ.get("MOCKDECK_SMOKE_CA"))
    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler({}),
        urllib.request.HTTPSHandler(context=context),
    )

    def request(scheme, path):
        url = f"{scheme}://{origin.netloc}{path}"
        try:
            with opener.open(url, timeout=15) as response:
                return response.status, response.read().decode("utf-8"), response.url
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode("utf-8"), error.url

    for scheme in ("http", "https"):
        status, _, _ = request(scheme, "/up")
        assert status == 200, f"{scheme} health returned {status}"
        status, _, _ = request(scheme, "/.env")
        assert status == 403, f"{scheme} dotfile guard returned {status}"
        status, body, final_url = request(scheme, "/dashboard")
        assert status == 200, f"{scheme} dashboard/login returned {status}"
        assert urllib.parse.urlsplit(final_url).scheme == scheme, "Unexpected protocol redirect"
        assert f'{scheme}://{origin.netloc}/css/' in body, "Wrong dashboard asset origin"
        other = "https" if scheme == "http" else "http"
        assert f'{other}://{origin.netloc}/css/' not in body, "Mixed protocol assets"
        print(f"{scheme}: health, dashboard assets, dotfile denial and no protocol redirect passed")

    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(lambda scheme: request(scheme, "/up")[0], ["http", "https"] * 8))
    assert all(status == 200 for status in results), results
    print(f"16 concurrent requests passed on the same external port {origin.port}")


if __name__ == "__main__":
    main()
