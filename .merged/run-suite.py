import pathlib,subprocess,os,re,json,sys,time
root=pathlib.Path(__file__).resolve().parent/'src'
tests=sorted((root/'tools').glob('test-*.php'))+sorted((root/'server/tests').glob('*.php'))
libraries={'chat_assignment_contract.php','chat_closure_contract.php','chat_platform_contract.php','chat_repair_contract.php'}
tests=[p for p in tests if not p.name.endswith('_fixture.php') and p.name not in libraries and not (p.name.endswith('_mysql.php') and p.with_name(p.stem+'_strict.php').exists())]
reports=[]
for p in tests:
    rel=str(p.relative_to(root));before=time.monotonic()
    try:
        r=subprocess.run(['php','-d','error_reporting=-1','-d','display_errors=stderr',str(p)],cwd=root,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=150)
        diagnostics=re.findall(r'(?im)^(?:PHP )?(?:Warning|Deprecated|Notice|Fatal error|Parse error):.*$',r.stdout+'\n'+r.stderr)
        skipped=bool(re.search(r'(?i)(?:SKIP|skipped|未启用|未开启)',r.stdout))
        helper=not r.stdout.strip() and not r.stderr.strip()
        status='FAIL' if r.returncode or diagnostics else ('SKIP' if skipped else ('LIBRARY' if helper else 'PASS'))
        item={'path':rel,'status':status,'exit':r.returncode,'seconds':round(time.monotonic()-before,2),'diagnostics':diagnostics}
        if status=='FAIL':
            item['stdout']=r.stdout[-12000:];item['stderr']=r.stderr[-6000:]
            print('FAIL_DETAIL',json.dumps(item,ensure_ascii=False),flush=True)
    except subprocess.TimeoutExpired:
        item={'path':rel,'status':'TIMEOUT','seconds':150}
    reports.append(item);print('PHP83_TEST',item['status'],rel,flush=True)
(root.parent/'suite-results.json').write_text(json.dumps(reports,ensure_ascii=False,indent=2))
counts={s:sum(x['status']==s for x in reports) for s in ['PASS','FAIL','SKIP','LIBRARY','TIMEOUT']}
print('PHP83_SUITE',json.dumps(counts),flush=True)
sys.exit(1 if counts['FAIL'] or counts['TIMEOUT'] else 0)
