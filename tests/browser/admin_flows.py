"""Drive the admin's from-scratch election creation path: create an election,
add positions and candidates with a real photo upload, then vote in it.

Usage:
    php -S 127.0.0.1:8400 -t public bin/dev_server.php &
    BASE_URL=http://127.0.0.1:8400 python3 tests/browser/admin_flows.py

Needs Playwright with Chromium. The suite provisions whatever accounts it needs,
so it can be run repeatedly against the same database.
"""
import os, sys, pathlib, re, subprocess, time
from playwright.sync_api import sync_playwright

APP_DIR = str(pathlib.Path(__file__).resolve().parents[2])
BASE = os.environ.get("BASE_URL", "http://127.0.0.1:8400").rstrip("/")
SHOTS = pathlib.Path(os.environ.get("SHOT_DIR", APP_DIR + "/screenshots"))
SHOTS.mkdir(exist_ok=True)
RUN_ID = str(int(time.time()))
RUN = RUN_ID

passed = failed = 0
def check(cond, what):
    global passed, failed
    if cond: passed += 1; print(f"  OK   {what}")
    else:    failed += 1; print(f"  FAIL {what}")

# A real photo to upload through the form.
photo = str(pathlib.Path(SHOTS).parent / "_upload_test.png")
subprocess.run(["php", "-r",
    f"$im=imagecreatetruecolor(1200,1500);imagefill($im,0,0,imagecolorallocate($im,180,60,90));"
    f"imagepng($im,'{photo}');"], check=True)

with sync_playwright() as pw:
    b = pw.chromium.launch()
    ctx = b.new_context(viewport={"width": 1280, "height": 900})
    page = ctx.new_page()
    errs = []
    page.on("pageerror", lambda e: errs.append(str(e)))

    page.goto(BASE + "/login")
    page.fill("#email", "admin@alumni.test"); page.fill("#password", "admin12345")
    page.click("button[type=submit]"); page.wait_for_load_state()

    print("\nAdmin creates an election from scratch")
    page.goto(BASE + "/admin/elections/new")
    check("New election" in page.inner_text("body"), "the new-election form renders")

    title = f"Chapter Committee {RUN}"
    page.fill("#title", title)
    page.fill("#description", "Electing the regional chapter committee.")
    page.select_option("#timezone", "Europe/London")
    # opens in the past so voting is live immediately, closes in the future
    page.fill("#starts_at", "2026-09-01T09:00")
    page.fill("#ends_at",   "2027-01-31T17:00")
    page.select_option("#results_mode", "after_close")
    # Scope to the form: a bare button[type=submit] also matches the header's
    # Sign out button, which appears earlier in the DOM.
    page.click("form[action='/admin/elections'] button[type=submit]")
    page.wait_for_load_state()

    body = page.inner_text("body")
    check(title in body, "the election is created and opened for management")
    check("/admin/elections/" in page.url, "and we land on its management page")
    eid = re.search(r"/admin/elections/(\d+)", page.url).group(1)

    # --- validation must actually reject a bad window
    page.fill("#ends_at", "2026-08-01T09:00")   # before the start
    page.click("form[action='/admin/elections/" + eid + "'] button[type=submit]")
    page.wait_for_load_state()
    check("close after it opens" in page.inner_text("body").lower(),
          "an end time before the start time is rejected with a clear message")
    # restore a valid window
    page.fill("#ends_at", "2027-01-31T17:00")
    page.click("form[action='/admin/elections/" + eid + "'] button[type=submit]")
    page.wait_for_load_state()

    print("\nAdding positions and candidates")
    page.goto(BASE + f"/admin/elections/{eid}")
    page.locator("details.admin-add", has_text="Add a position").first.click()
    # Scope to the positions form — the settings form above also has name=title.
    pform = page.locator(f"form[action='/admin/elections/{eid}/positions']")
    pform.locator("input[name=title]").fill("Chapter Chair")
    pform.locator("textarea[name=description]").fill("Leads the regional chapter.")
    pform.locator("button[type=submit]").click()
    page.wait_for_load_state()
    check("Chapter Chair" in page.inner_text("body"), "the position is added")

    # add two candidates, the first with a real uploaded photo
    for i, (name, headline) in enumerate([("Amara Nwachukwu", "Class of 2012"),
                                          ("Joseph Idowu", "Class of 2006")]):
        page.goto(BASE + f"/admin/elections/{eid}")
        page.locator("details.admin-add", has_text="Add a candidate to").first.click()
        # action$='/candidates' is the ADD form; an existing candidate's edit
        # form is /admin/candidates/<id>/update and would also match *=.
        form = page.locator("form[action$='/candidates']").first
        form.locator("input[name=full_name]").fill(name)
        form.locator("input[name=headline]").fill(headline)
        form.locator("textarea[name=bio]").fill("Standing for chapter chair.")
        if i == 0:
            form.locator("input[name=photo]").set_input_files(photo)
        form.locator("button[type=submit]").click()
        page.wait_for_load_state()
        check(name in page.inner_text("body"), f"candidate {name} is added")

    # the uploaded photo must actually be served
    page.goto(BASE + f"/elections/{eid}")
    broken = page.evaluate(
        "Array.from(document.querySelectorAll('img.cand-photo')).filter(i=>!i.complete||i.naturalWidth===0).length")
    imgs = page.locator("img.cand-photo").count()
    check(imgs == 1, f"the candidate with a photo shows one ({imgs})")
    check(broken == 0, "and the uploaded photo is served, not broken")
    src = page.locator("img.cand-photo").first.get_attribute("src")
    r = page.request.get(BASE + src)
    check(r.status == 200, f"the photo URL returns 200 ({src})")
    check("image/jpeg" in r.headers.get("content-type", ""),
          f"and is served as a JPEG regardless of the PNG that was uploaded ({r.headers.get('content-type')})")
    page.screenshot(path=str(SHOTS / "21-admin-created-election.png"))

    print("\nA voter votes in the newly created election")
    email = f"fresh{RUN}@alumni.test"
    subprocess.run(["php", "bin/console.php", "roll-add", email], cwd=APP_DIR, check=True, capture_output=True)
    subprocess.run(["php", "bin/console.php", "create-user", email, "password123", "voter", "Fresh Voter"],
                   cwd=APP_DIR, check=True, capture_output=True)

    page.click("form[action='/logout'] button"); page.wait_for_load_state()
    page.goto(BASE + "/login")
    page.fill("#email", email); page.fill("#password", "password123")
    page.click("button[type=submit]"); page.wait_for_load_state()

    page.goto(BASE + f"/elections/{eid}/vote")
    check("Your ballot" in page.inner_text("body"), "the new election accepts a ballot right away")
    page.locator("input[type=radio][value]:not([value='0'])").first.check()
    page.once("dialog", lambda d: d.accept())
    page.click("#submit-ballot"); page.wait_for_load_state()
    check("Your vote is recorded" in page.inner_text("body"), "the vote in the brand-new election is recorded")

    # results stay sealed because the mode is after_close and it is still open
    page.goto(BASE + f"/elections/{eid}/results")
    check("not public yet" in page.inner_text("body").lower(),
          "results of the new election stay sealed while voting is open")

    b.close()

print("\n" + "-"*58)
check(not errs, f"no JavaScript errors ({errs[:2]})")
print(f"{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
