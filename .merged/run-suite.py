import pathlib,subprocess,os,re,json,sys,time
root=pathlib.Path(__file__).resolve().parent/'src'
tests=sorted((root/'tools').glob('test-*.php'))+sorted((root/'server/tests').glob('*.php'))
libraries={'chat_assignment_contract.php','chat_closure_contract.php','chat_platform_contract.php','chat_repair_contract.php'}
tests=[p for p in tests if not p.name.endswith('_fixture.php') and p.name not in libraries and not (p.name.endswith('_mysql.php') and p.with_name(p.stem+'_strict.php').exists())]
selected=["server/tests/v260_test_center_expansion_mysql_strict.php","server/tests/v260_test_center_platform_measurement_mysql_strict.php"]
tests=[p for p in tests if str(p.relative_to(root)) in selected]
reports=[]
for p in tests:
    rel=str(p.relative_to(root));before=time.monotonic()
    try:
        if '_mysql' in p.name:
            source=p.read_text()
            if p.name.endswith('_strict.php'):source+=p.with_name(p.name.replace('_strict.php','.php')).read_text()
            databases={'worker_test'}|set(re.findall(r'''['\"](worker_test_[a-z0-9_]+)['\"]''',source))
            seed=subprocess.run(['php',str(root.parent/'seed-db.php'),*(['--core'] if p.name.startswith('v260_') else []),*sorted(databases)],cwd=root,text=True,capture_output=True,timeout=90)
            if seed.returncode:raise RuntimeError(seed.stderr)
        r=subprocess.run(['php','-d','error_reporting=-1','-d','display_errors=stderr',str(p)],cwd=root,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=150)
        diagnostics=re.findall(r'(?im)^(?:PHP )?(?:Warning|Deprecated|Notice|Fatal error|Parse error):.*$',r.stdout+'\n'+r.stderr)
        skipped=bool(re.search(r'(?im)^\\s*(?:\\[SKIP\\]|SKIP[: ]|[^\\n]*验证未启用|[^\\n]*测试未启用)',r.stdout))
        helper=not r.stdout.strip() and not r.stderr.strip()
        status='FAIL' if r.returncode or diagnostics else ('SKIP' if skipped else ('LIBRARY' if helper else 'PASS'))
        item={'path':rel,'status':status,'exit':r.returncode,'seconds':round(time.monotonic()-before,2),'diagnostics':diagnostics}
        if status=='FAIL':
            item['stdout']=r.stdout[-12000:];item['stderr']=r.stderr[:4000]+r.stderr[-2000:]
            print('FAIL_DETAIL',json.dumps(item,ensure_ascii=False),flush=True)
    except subprocess.TimeoutExpired:
        item={'path':rel,'status':'TIMEOUT','seconds':150}
    reports.append(item);print('PHP83_TEST',item['status'],rel,flush=True)
(root.parent/'suite-results.json').write_text(json.dumps(reports,ensure_ascii=False,indent=2))
counts={s:sum(x['status']==s for x in reports) for s in ['PASS','FAIL','SKIP','LIBRARY','TIMEOUT']}
print('PHP83_SUITE',json.dumps(counts),flush=True)
sys.exit(1 if counts['FAIL'] or counts['TIMEOUT'] else 0)
