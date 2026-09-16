"""End-to-end browser coverage: public pages, registration and email
confirmation, casting a ballot, the one-vote rule, auditor and admin views.

Usage:
    php -S 127.0.0.1:8400 -t public bin/dev_server.php &
    BASE_URL=http://127.0.0.1:8400 python3 tests/browser/site_flows.py

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

passed, failed = 0, 0
console_errors = []

def check(cond, what):
    global passed, failed
    if cond:
        passed += 1
        print(f"  OK   {what}")
    else:
        failed += 1
        print(f"  FAIL {what}")

def shot(page, name):
    page.screenshot(path=str(SHOTS / f"{name}.png"))

with sync_playwright() as pw:
    browser = pw.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1280, "height": 860})
    page = ctx.new_page()
    page.on("console", lambda m: console_errors.append(m.text)
             if m.type == "error" and "Failed to load resource" not in m.text else None)
    page.on("pageerror", lambda e: console_errors.append(str(e)))

    # ---------------------------------------------------------- public pages
    print("\nPublic pages")
    page.goto(BASE + "/")
    body = page.inner_text("body")
    check("Alumni Association Executive 2026" in body, "home lists the open election by name")
    check("voting open" in body.lower(), "home shows the open election as open")
    check("not open yet" in body.lower(), "home shows the upcoming election as not yet open")
    check("Sign in" in body, "home offers sign in to a signed-out visitor")
    shot(page, "01-home-signed-out")

    page.goto(BASE + "/elections/1")
    body = page.inner_text("body")
    check("President" in body and "Secretary" in body and "Treasurer" in body,
          "election page lists all three positions")
    check("Ngozi Eze" in body and "Ibrahim Danjuma" in body, "candidates are listed")
    imgs = page.locator("img.cand-photo")
    check(imgs.count() >= 8, f"candidate photos render ({imgs.count()} images)")
    # a photo that 404s has naturalWidth 0
    broken = page.evaluate(
        "Array.from(document.querySelectorAll('img.cand-photo')).filter(i => !i.complete || i.naturalWidth === 0).length")
    check(broken == 0, f"no broken candidate photos (broken={broken})")
    shot(page, "02-election-candidates")

    # results must be sealed for a signed-out visitor
    page.goto(BASE + "/elections/1/results")
    body = page.inner_text("body")
    check("not public yet" in body.lower(), "results are sealed from a signed-out visitor")
    check("Ngozi Eze" not in body, "and no candidate tally leaks onto the sealed page")
    shot(page, "03-results-sealed")

    # candidate profile
    page.goto(BASE + "/candidates/1")
    body = page.inner_text("body")
    check("Manifesto" in body, "candidate profile shows the manifesto")
    check("standing for" in body.lower(), "and the position being contested")
    shot(page, "04-candidate-profile")

    # voting while signed out must be refused
    page.goto(BASE + "/elections/1/vote")
    check("/login" in page.url, "visiting the ballot signed out redirects to sign in")

    page.goto(BASE + "/register")
    check("Register" in page.inner_text("body"), "registration page renders")
    shot(page, "05-register")

    # ------------------------------------------------- registration + voting
    # A fresh alumnus every run, created through the real signup path, so the
    # suite does not depend on seed state and can be run repeatedly.
    print("\nRegistration: roll, signup, email confirmation")
    NEW_EMAIL = f"newalum{RUN_ID}@alumni.test"

    # Not on the roll yet: signup must be refused.
    page.goto(BASE + "/register")
    page.fill("#full_name", "New Alum")
    page.fill("#grad_year", "2013")
    page.fill("#email", NEW_EMAIL)
    page.fill("#password", "password123")
    page.click("button[type=submit]")
    page.wait_for_load_state()
    check("not on the alumni roll" in page.inner_text("body").lower(),
          "an address that is not on the roll cannot register")

    # Admin puts the address on the roll.
    subprocess.run(["php", "bin/console.php", "roll-add", NEW_EMAIL],
                   cwd=APP_DIR, check=True, capture_output=True)

    page.goto(BASE + "/register")
    page.fill("#full_name", "New Alum")
    page.fill("#grad_year", "2013")
    page.fill("#email", NEW_EMAIL)
    page.fill("#password", "password123")
    page.click("button[type=submit]")
    page.wait_for_load_state()
    check("check your email" in page.inner_text("body").lower(),
          "an address on the roll can register")
    shot(page, "05b-registered")

    # Unconfirmed: cannot sign in yet.
    page.goto(BASE + "/login")
    page.fill("#email", NEW_EMAIL)
    page.fill("#password", "password123")
    page.click("button[type=submit]")
    page.wait_for_load_state()
    check("confirm your email" in page.inner_text("body").lower(),
          "an unconfirmed account cannot sign in")

    # Pull the confirmation link out of the mail log, exactly as a real
    # recipient would click it.
    mail = (pathlib.Path(APP_DIR) / "data" / "mail.log").read_text()
    m = re.findall(r"/verify\?token=([A-Za-z0-9]+)", mail)
    check(bool(m), "a confirmation email was produced with a verify link")
    # Visit the link against the host under test rather than whatever base_url
    # the install is configured with, so the suite works on any port or domain.
    page.goto(BASE + "/verify?token=" + m[-1])
    check("confirmed" in page.inner_text("body").lower(), "the link confirms the address")
    shot(page, "05c-verified")

    print("\nVoter: casting a ballot")
    page.goto(BASE + "/login")
    page.fill("#email", NEW_EMAIL)
    page.fill("#password", "password123")
    page.click("button[type=submit]")
    page.wait_for_load_state()
    check(NEW_EMAIL in page.inner_text("body"), "the confirmed voter is signed in")

    page.goto(BASE + "/elections/1/vote")
    body = page.inner_text("body")
    check("Your ballot" in body, "the ballot paper renders")
    check("Abstain" in body, "an explicit abstain option is offered per position")
    radios = page.locator("input[type=radio]")
    check(radios.count() == 11, f"one radio per candidate plus abstain per position (got {radios.count()})")
    shot(page, "06-ballot")

    # Reading a candidate's profile from the ballot must not select them.
    abstain_first = page.locator("input[name='position[1]'][value='0']")
    check(abstain_first.is_checked(), "each position starts on Abstain, nothing pre-selected for a candidate")
    with page.expect_popup() as popup_info:
        page.click(".option a.option-link >> nth=0")
    popup_info.value.close()
    check(abstain_first.is_checked(),
          "clicking 'Read the full profile' does NOT change the ballot selection")
    check(page.locator("input[name='position[1]'][value='1']").is_checked() is False,
          "and does not silently select that candidate")

    # pick a candidate in each position
    page.check("input[name='position[1]'][value='2']")   # Robert Adeniyi
    page.check("input[name='position[2]'][value='5']")   # Sandra Duarte
    page.check("input[name='position[3]'][value='7']")   # Lucy Nwosu
    page.once("dialog", lambda d: d.accept())
    page.click("#submit-ballot")
    page.wait_for_load_state()

    body = page.inner_text("body")
    check("Your vote is recorded" in body, "the vote is accepted and confirmed")
    receipt = page.locator(".receipt").inner_text().strip()
    check(len(receipt) == 10, f"a receipt code is shown ({receipt})")
    shot(page, "07-vote-recorded")

    # the central rule, through the browser
    page.goto(BASE + "/elections/1/vote")
    body = page.inner_text("body")
    check("already voted" in body.lower(), "returning to the ballot says the vote is already cast")
    check(receipt in body, "and shows the same receipt code back")
    shot(page, "08-already-voted")

    page.goto(BASE + "/account")
    body = page.inner_text("body")
    check(receipt in body, "the voting record shows the receipt")
    check("does not show who you voted for" in body, "and states that the choice is not stored")
    check("Robert Adeniyi" not in body, "the voter's own record does NOT reveal their choice")
    shot(page, "09-account-record")

    # a voter must not reach the admin area
    page.goto(BASE + "/admin")
    check("administrators" in page.inner_text("body").lower(), "a voter is refused the admin area")
    page.goto(BASE + "/admin/users")
    check("administrators" in page.inner_text("body").lower(), "and the accounts page too")

    # -------------------------------------------------------------- auditor
    print("\nAuditor: read-only oversight")
    page.goto(BASE + "/login")
    page.click("form[action='/logout'] button")
    page.wait_for_load_state()
    page.goto(BASE + "/login")
    page.fill("#email", "auditor@alumni.test")
    page.fill("#password", "auditor12345")
    page.click("button[type=submit]")
    page.wait_for_load_state()

    check(page.url.rstrip("/").endswith("/admin"),
          f"an auditor lands on the oversight area, not the voter home page (got {page.url})")
    body = page.inner_text("body")
    check("Oversight" in page.locator("h1").inner_text(), "the heading names it as oversight")
    check("read-only" in body.lower(), "which says it is read-only")
    check("New election" not in body, "and offers no way to create an election")
    shot(page, "10-auditor-dashboard")

    page.goto(BASE + "/elections/1/vote")
    check("not open to you" in page.inner_text("body").lower(), "an auditor cannot reach a ballot")

    page.goto(BASE + "/admin/elections/1/turnout")
    body = page.inner_text("body")
    check("Turnout" in body, "the auditor can see turnout")
    check("cannot show who voted for whom" in body, "which states the anonymity limit plainly")
    check("hash chain" in body.lower() or "chain" in body.lower(), "and the integrity check")
    shot(page, "11-turnout")

    page.goto(BASE + "/admin/audit")
    body = page.inner_text("body")
    check("Audit trail" in body, "the auditor can read the audit trail")
    check("ballot.cast" in body, "which records that ballots were cast")
    check("append-only" in body.lower() or "cannot be edited" in body.lower(),
          "and explains that it cannot be rewritten")
    shot(page, "12-audit-trail")

    # ---------------------------------------------------------------- admin
    print("\nAdmin")
    page.click("form[action='/logout'] button")
    page.wait_for_load_state()
    page.goto(BASE + "/login")
    page.fill("#email", "admin@alumni.test")
    page.fill("#password", "admin12345")
    page.click("button[type=submit]")
    page.wait_for_load_state()

    body = page.inner_text("body")
    check("Administration" in body, "admin lands on the administration dashboard")
    check("eligible voters" in body.lower(), "with the eligible-voter count")
    check("New election" in body, "and a way to create an election")
    shot(page, "13-admin-dashboard")

    page.goto(BASE + "/admin/elections/1")
    body = page.inner_text("body")
    check("ballot paper is locked" in body.lower(),
          "an election with votes cast reports its ballot as locked")
    check("Add a position" not in body, "and offers no control to add a position")
    shot(page, "14-admin-manage-locked")

    page.goto(BASE + "/admin/elections/2")
    body = page.inner_text("body")
    check("Add a position" in body, "an election that has not opened can still be edited")
    shot(page, "15-admin-manage-open")

    page.goto(BASE + "/admin/roll")
    check("Alumni roll" in page.inner_text("body"), "the alumni roll page renders")
    shot(page, "16-admin-roll")

    page.goto(BASE + "/admin/users")
    body = page.inner_text("body")
    check("voter1@alumni.test" in body, "accounts are listed")
    check("What the roles mean" in body, "with the role explanation")
    shot(page, "17-admin-users")

    # admin sees results even though the election is still open
    page.goto(BASE + "/elections/1/results")
    body = page.inner_text("body")
    check("provisional" in body.lower(), "an open election's results are labelled provisional")
    check("ballots submitted" in body.lower(), "turnout is shown")
    check("Ngozi Eze" in body, "candidate counts are shown to the admin")
    check("intact" in body.lower() or "verify" in body.lower(), "integrity statement is present")
    shot(page, "18-results-admin")

    # admin must not be able to vote
    page.goto(BASE + "/elections/1/vote")
    check("not open to you" in page.inner_text("body").lower(), "an administrator cannot reach a ballot")

    # ------------------------------------------------------------ 404 / mobile
    print("\nEdge cases and mobile")
    r = page.goto(BASE + "/no/such/page")
    check(r.status == 404, f"an unknown path returns HTTP 404, not a 500 (got {r.status})")
    check("not found" in page.inner_text("body").lower(), "and renders a proper not-found page")

    r = page.goto(BASE + "/elections/999")
    check(r.status == 404, f"an unknown election returns HTTP 404 (got {r.status})")
    check("not found" in page.inner_text("body").lower(), "and renders a proper not-found page")

    r = page.goto(BASE + "/candidates/999")
    check(r.status == 404, f"an unknown candidate returns HTTP 404 (got {r.status})")

    mob = ctx.new_page()
    mob.set_viewport_size({"width": 390, "height": 780})
    mob.goto(BASE + "/")
    mob.screenshot(path=str(SHOTS / "19-mobile-home.png"))
    scroll_w = mob.evaluate("document.documentElement.scrollWidth")
    check(scroll_w <= 390, f"home does not scroll sideways on a phone (scrollWidth={scroll_w})")

    mob.goto(BASE + "/elections/1")
    mob.screenshot(path=str(SHOTS / "20-mobile-election.png"))
    scroll_w = mob.evaluate("document.documentElement.scrollWidth")
    check(scroll_w <= 390, f"election page does not scroll sideways on a phone (scrollWidth={scroll_w})")

    browser.close()

print("\n" + "-" * 58)
check(len(console_errors) == 0, f"no JavaScript errors anywhere ({console_errors[:3]})")
print(f"{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
