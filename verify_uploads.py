import os
import time
from playwright.sync_api import sync_playwright

def run_cuj(page):
    # 1. Login
    page.goto("http://127.0.0.1:8000/login.php")
    page.wait_for_timeout(500)

    # Fill credentials
    page.fill("input[name='email']", "sjenkins@clinicflow.com")
    page.fill("input[name='password']", "password")
    page.click("button[type='submit']")
    page.wait_for_timeout(1000)

    # Navigate to Upload Documents Hub
    page.goto("http://127.0.0.1:8000/uploads.php")
    page.wait_for_timeout(1000)

    # Take screenshot of Uploads Hub main page (with Storage Allocation Plan card)
    page.screenshot(path="/home/jules/verification/screenshots/uploads_hub.png")
    page.wait_for_timeout(500)

    # Navigate to Admin Tenants
    page.goto("http://127.0.0.1:8000/admin.php?tab=tenants")
    page.wait_for_timeout(1000)

    # Open Tenant Modal
    page.click("button:has-text('Add New Clinic Tenant')")
    page.wait_for_timeout(500)

    # Take screenshot of Tenant Allocation Modal
    page.screenshot(path="/home/jules/verification/screenshots/tenant_allocation_modal.png")
    page.wait_for_timeout(500)

if __name__ == "__main__":
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            record_video_dir="/home/jules/verification/videos"
        )
        page = context.new_page()
        try:
            run_cuj(page)
        finally:
            context.close()
            browser.close()
