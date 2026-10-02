"""Real-browser check of the web UI (search main display + right-hand downloads sidebar).

Needs Playwright and a Chromium-based browser:  pip install playwright   (uses the installed Microsoft Edge/Chrome)
Run against the fake-engine test stack (deterministic):
    WEB_PORT=18080 docker compose -p ytd-test -f docker-compose.yml -f docker-compose.test.yml up -d --build
    python tests/e2e/ui_check.py http://localhost:18080
"""
from __future__ import annotations

import sys

from playwright.sync_api import expect, sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:18080"
results: list[tuple[str, bool]] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    results.append((name, ok))
    print("PASS" if ok else "FAIL", name, detail)


with sync_playwright() as p:
    browser = None
    for channel in ("msedge", "chrome", None):
        try:
            browser = p.chromium.launch(channel=channel, headless=True)
            break
        except Exception:  # noqa: BLE001 - try the next browser
            continue
    if browser is None:
        sys.exit("no Chromium-based browser available")
    page = browser.new_page(viewport={"width": 1280, "height": 900})
    errors: list[str] = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    # thumbnails of the fake video ids 404 on YouTube's image host; that is expected, anything else is a bug
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" and "Failed to load resource" not in m.text else None)
    page.goto(BASE)

    # layout: search on the main display, downloads sidebar on the right
    search_box = page.locator("#searchInput").bounding_box()
    side_box = page.locator("#jobsHeading").bounding_box()
    check("sidebar is to the right of the search display", side_box["x"] > search_box["x"] + search_box["width"] - 5, f"search x={search_box['x']:.0f}, sidebar x={side_box['x']:.0f}")
    check("sidebar starts empty", page.locator("#jobsEmpty").is_visible() and page.locator("#jobList > div").count() == 0)

    # invalid input
    page.click("#searchBtn")
    check("empty search shows a friendly message", "type something" in page.locator("#searchError").inner_text())
    page.fill("#searchInput", "http://localhost/secret")
    page.click("#searchBtn")
    check("non-YouTube link is rejected", page.locator("#searchError").is_visible())

    # search
    page.fill("#searchInput", "never gonna")
    page.click("#searchBtn")
    expect(page.locator("#results li")).to_have_count(4, timeout=15000)
    check("search shows results with a Download button each", page.locator("#results li button:has-text('Download')").count() == 4, page.locator("#resultsInfo").inner_text())
    first_title = page.locator("#results li .result-title").first.inner_text()
    check("result shows title, uploader and duration", first_title == "Result 1 for never gonna" and "Fake Channel · 3:00" in page.locator("#results li").first.inner_text())

    # several downloads: a long one, then two fast ones, started back to back
    slow_row = page.locator("#results li", has_text="Result 4")
    slow_row.locator("select").select_option("mp4")
    slow_row.locator("button:has-text('Download')").click()
    page.locator("#results li").nth(0).locator("button:has-text('Download')").click()
    page.locator("#results li").nth(1).locator("select").select_option("mp3")
    page.locator("#results li").nth(1).locator("button:has-text('Download')").click()
    expect(page.locator("#jobList .job-card")).to_have_count(3, timeout=10000)
    check("each Download press adds a card to the sidebar", True, f"{page.locator('#jobList .job-card').count()} cards, badge={page.locator('#jobCount').inner_text()}")
    check("search stays usable while downloading", page.locator("#searchBtn").is_enabled() and page.locator("#results li").count() == 4)

    cards = page.locator("#jobList .job-card")
    expect(page.locator("#jobList .job-card", has_text="Complete:")).to_have_count(2, timeout=60000)
    slow_card = page.locator("#jobList .job-card", has_text="Result 4")
    check("the long download is still running while the others completed", "Complete" not in slow_card.inner_text() and slow_card.locator("button:has-text('Cancel')").is_visible(), slow_card.locator(".job-status").inner_text())
    done = page.locator("#jobList .job-card", has_text="Complete:").first
    href = done.locator("a:has-text('Download File')").get_attribute("href")
    check("finished card offers Download File", href.startswith("/api/download/") and href.endswith("/file"), href)
    with page.expect_download() as dl:
        done.locator("a:has-text('Download File')").click()
    check("file downloads in the browser", dl.value.suggested_filename.endswith((".mp3", ".mp4")), dl.value.suggested_filename)

    # progress animation / values shown for the running job
    check("running card shows progress", slow_card.locator(".progress-bar").get_attribute("aria-valuenow") is not None or slow_card.locator(".progress-bar.indeterminate").count() == 1)

    # cancel only that one
    slow_card.locator("button:has-text('Cancel')").click()
    expect(slow_card.locator(".job-status")).to_contain_text("cancelled", timeout=20000)
    check("cancel stops only that download", page.locator("#jobList .job-card", has_text="Complete:").count() == 2)

    # dismiss + history tab
    slow_card.locator("button:has-text('Dismiss')").click()
    check("Dismiss removes the card", page.locator("#jobList .job-card").count() == 2 and page.locator("#jobCount").inner_text() == "2")
    done.locator("button:has-text('Open Folder')").click()
    expect(page.locator("#historyTable")).to_be_visible(timeout=10000)
    check("Open Folder shows the history list", page.locator("#historyBody tr").count() >= 2)

    # reload keeps unfinished/known cards? (finished ones are fetched from the server again)
    page.reload()
    page.wait_for_timeout(1500)
    check("reload restores the sidebar cards", page.locator("#jobList .job-card").count() == 2)

    # pasted link becomes a single result
    page.click("#tab-download")
    page.fill("#searchInput", "https://youtu.be/okvideo0003")
    page.click("#searchBtn")
    expect(page.locator("#results li")).to_have_count(1, timeout=15000)
    check("pasted link gives one result", "Fake video okvideo0003" in page.locator("#results li").inner_text())
    check("no JavaScript errors", not errors, "; ".join(errors)[:300])
    browser.close()

failed = [n for n, ok in results if not ok]
print(f"\n{len(results) - len(failed)}/{len(results)} UI checks passed")
sys.exit(1 if failed else 0)
