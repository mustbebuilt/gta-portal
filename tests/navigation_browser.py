"""Local Chromium navigation checks using a disposable fictional Laravel database.
This is selected keyboard/responsive coverage, not full accessibility/device acceptance.
"""
from pathlib import Path
import argparse,base64,json,os,secrets,signal,socket,subprocess,tempfile,time
from urllib.request import urlopen
from playwright.sync_api import sync_playwright,expect
p=argparse.ArgumentParser();p.add_argument('--php',default='php');p.add_argument('--chromium',default='/usr/bin/chromium');p.add_argument('--output',required=True);args=p.parse_args()
project=Path(__file__).resolve().parent.parent;checks=[];server=None

def passed(name):checks.append({'name':name,'passed':True})
with tempfile.TemporaryDirectory(prefix='gta-navigation-') as temporary:
    temp=Path(temporary);storage=temp/'storage';db=temp/'fictional.sqlite';db.touch()
    for part in ['app/private','framework/cache/data','framework/cache/locks','framework/views','framework/sessions','logs']:(storage/part).mkdir(parents=True,exist_ok=True)
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    origin=f'http://127.0.0.1:{port}';env=os.environ.copy();env.update(APP_ENV='local',APP_DEBUG='false',APP_URL=origin,APP_KEY='base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),DB_CONNECTION='sqlite',DB_DATABASE=str(db),DB_URL='',CACHE_STORE='file',SESSION_DRIVER='file',MAIL_MAILER='array',QUEUE_CONNECTION='sync',LARAVEL_STORAGE_PATH=str(storage),APP_CONFIG_CACHE=str(temp/'config.php'),APP_ROUTES_CACHE=str(temp/'routes.php'))
    def artisan(*command):
        r=subprocess.run([args.php,'artisan',*command,'--no-interaction'],cwd=project,env=env,capture_output=True,text=True,timeout=60);assert r.returncode==0,'Fictional fixture failed';return r.stdout
    try:
        artisan('migrate');seed=artisan('gta:demo');password=seed.split('Local test password for these accounts: ',1)[1].splitlines()[0].strip()
        server=subprocess.Popen([args.php,'-S',f'127.0.0.1:{port}','-t','public'],cwd=project,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
        for _ in range(100):
            try:
                if urlopen(origin+'/up',timeout=1).status==200:break
            except OSError:time.sleep(.05)
        else:raise AssertionError('Loopback fixture did not start')
        with sync_playwright() as pw:
            browser=pw.chromium.launch(executable_path=args.chromium,args=['--no-sandbox'])
            context=browser.new_context(viewport={'width':1366,'height':900});page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            page.goto(origin+'/login');page.keyboard.press('Tab');expect(page.locator('.skip-link')).to_be_focused();page.keyboard.press('Enter');expect(page.locator('#main-content')).to_be_focused();passed('First keyboard tab reaches a visible skip link and Enter focuses main content')
            expect(page.locator('#navigation-toggle')).to_have_count(0);passed('Signed-out screen has no unusable menu toggle')
            page.get_by_label('Email address',exact=True).fill('admin@example.test');page.get_by_label('Password',exact=True).fill(password);page.get_by_role('button',name='Sign in',exact=True).click();page.wait_for_url('**/apprentices')
            expect(page.locator('#portal-navigation')).to_be_visible();expect(page.locator('#navigation-toggle')).to_be_hidden();expect(page.locator('#portal-navigation [aria-current="page"]')).to_have_text('Apprentices');passed('Desktop navigation is visible and identifies the current apprentice section')
            page.locator('#portal-navigation').get_by_role('link',name='Dashboard',exact=True).click();expect(page.locator('#portal-navigation [aria-current="page"]')).to_have_text('Dashboard');page.locator('#portal-navigation').get_by_role('link',name='Help and how to',exact=True).click();expect(page.locator('#portal-navigation [aria-current="page"]')).to_have_text('Help and how to');passed('Dashboard and Help route changes retain accurate current-section markers')
            page.locator('h1').click();page.set_viewport_size({'width':390,'height':844});toggle=page.locator('#navigation-toggle');expect(toggle).to_be_visible();expect(toggle).to_have_attribute('aria-expanded','false');expect(page.locator('#portal-navigation')).to_be_hidden();passed('Phone viewport collapses the long menu behind an accurately labelled control')
            toggle.focus();page.keyboard.press('Enter');expect(toggle).to_have_attribute('aria-expanded','true');expect(page.locator('#portal-navigation')).to_be_visible();page.keyboard.press('Tab');expect(page.locator('#portal-navigation a').first).to_be_focused();page.keyboard.press('Escape');expect(toggle).to_be_focused();expect(toggle).to_have_attribute('aria-expanded','false');expect(page.locator('#portal-navigation')).to_be_hidden();passed('Keyboard opens menu, tabs into links and Escape closes it with focus returned')
            page.keyboard.press('Space');expect(page.locator('#portal-navigation')).to_be_visible();page.locator('#portal-navigation').get_by_role('link',name='Apprentices',exact=True).click();page.wait_for_url('**/apprentices');expect(page.locator('#portal-navigation')).to_be_hidden();passed('Space activates the menu and a real link loads its page with a fresh collapsed menu')
            page.get_by_role('link',name='Add apprentice',exact=True).click();expect(page.get_by_label('Learner reference *',exact=True)).to_be_visible();expect(page.get_by_role('button',name='Save apprentice',exact=True)).to_be_visible();assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1');passed('Apprentice form controls remain reachable without page-wide horizontal overflow on phone')
            page.get_by_label('Learner reference *',exact=True).fill('BAD REFERENCE');page.get_by_label('First name *',exact=True).fill('Fictional');page.get_by_label('Last name *',exact=True).fill('Learner');page.get_by_label('First day in learning',exact=True).fill('2026-11-13');page.get_by_label('Planned training end date',exact=True).fill('2026-11-12');page.get_by_role('button',name='Save apprentice',exact=True).click()
            expect(page.locator('[data-error-summary]')).to_be_focused();expect(page.get_by_label('First name *',exact=True)).to_have_value('Fictional');page.locator('[data-error-summary] a[href="#reference"]').click();expect(page.locator('#reference')).to_be_focused();passed('Rejected form focuses the error summary; its native link focuses the field and entered text is retained')
            page.set_viewport_size({'width':1366,'height':900});expect(page.locator('#portal-navigation')).to_be_visible();page.locator('#portal-navigation a').first.focus();page.set_viewport_size({'width':390,'height':844});expect(page.locator('#portal-navigation')).to_be_visible();expect(page.locator('#navigation-toggle')).to_have_attribute('aria-expanded','true');expect(page.locator('#portal-navigation a').first).to_be_focused();passed('Shrinking the viewport keeps a focused navigation link visible')
            page.keyboard.press('Escape');expect(page.locator('#navigation-toggle')).to_be_focused();page.set_viewport_size({'width':1366,'height':900});expect(page.locator('#navigation-toggle')).to_be_hidden();expect(page.locator('#portal-navigation [aria-current="page"]')).to_be_focused();passed('Returning to desktop moves focus from the hidden menu toggle to the current section')
            for width in [320,390,768,1024,1366]:
                page.set_viewport_size({'width':width,'height':900});page.goto(origin+'/apprentices');assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1')
            passed('Apprentice list has no page-wide horizontal overflow at five tested widths; table scrolling stays contained')
            page.set_viewport_size({'width':390,'height':844});page.get_by_role('button',name='Dark mode',exact=True).click();expect(page.locator('html')).to_have_attribute('data-theme','dark');page.locator('#navigation-toggle').click();expect(page.locator('#portal-navigation')).to_be_visible();passed('Theme switching and compact navigation work together')
            page.emulate_media(media='print');expect(page.locator('#navigation-toggle')).to_be_hidden();expect(page.locator('.skip-link')).to_be_hidden();page.emulate_media(media='screen');passed('Print media excludes the menu control and skip link')
            assert not errors;passed('Covered navigation interactions produce no uncaught JavaScript errors')
            cookies=context.cookies();fallback=browser.new_context(java_script_enabled=False,viewport={'width':390,'height':844});fallback.add_cookies(cookies);nojs=fallback.new_page();nojs.goto(origin+'/apprentices');expect(nojs.locator('#portal-navigation')).to_be_visible();expect(nojs.locator('#navigation-toggle')).to_be_hidden();expect(nojs.locator('#portal-navigation [aria-current="page"]')).to_have_text('Apprentices');passed('With JavaScript disabled the mobile navigation remains visible and usable')
            nojs.get_by_role('link',name='Add apprentice',exact=True).click();expect(nojs.get_by_role('button',name='Save apprentice',exact=True)).to_be_visible();passed('Core form navigation remains available with JavaScript disabled')
            fallback.close();context.close();browser.close()
        result={'passed':len(checks),'failed':0,'checks':checks,'browser':'Local headless Chromium via Playwright','database':'Disposable fictional SQLite','viewportWidths':[320,390,768,1024,1366],'actualCloudAccess':False,'realLearnerData':False,'limits':['Selected navigation/form reachability only; not full WCAG certification','No actual phone, Safari, Firefox, screen reader, touch-device or complete print visual acceptance','No hosted environment or real records used']}
        Path(args.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({'passed':len(checks),'failed':0}))
    except Exception:
        Path(args.output).write_text(json.dumps({'passed':len(checks),'failed':1,'checks':checks},indent=2)+'\n');raise
    finally:
        if server:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10)
