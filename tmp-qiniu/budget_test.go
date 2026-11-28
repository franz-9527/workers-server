package imagepipeline

import (
    "encoding/json"
    "fmt"
    "os"
    "os/exec"
    "path/filepath"
    "sync"
    "testing"
)

type Qiniu struct { config struct { Environment, BudgetRoot string } }

func fixture(t *testing.T) (*Qiniu, func(...string) []byte) {
    t.Helper()
    root := t.TempDir()
    dir := filepath.Join(root,"storage/private")
    if err:=os.MkdirAll(dir,0700);err!=nil{t.Fatal(err)}
    q:=&Qiniu{}
    q.config.Environment="test"
    q.config.BudgetRoot=filepath.Join(dir,"qiniu-budget")
    run:=func(args ...string) []byte {
        cmd:=exec.Command("php",append([]string{"fixture.php"},args...)...)
        cmd.Env=append(os.Environ(),"FIXTURE_ROOT="+root)
        b,e:=cmd.CombinedOutput()
        if e!=nil{t.Fatalf("php %v: %s %v",args,b,e)}
        return b
    }
    return q,run
}
func TestPHPGoSharedLedger(t *testing.T){
    q,php:=fixture(t)
    php("reserve","get","8192","4096")
    if _,e:=q.reserveBudget("upload",1024);e!=nil{t.Fatal(e)}
    month,e:=q.reserveBudget("get",8192);if e!=nil{t.Fatal(e)}
    q.releaseBudget(month,4096)
    var out struct{State budgetState}
    if e=json.Unmarshal(php("status"),&out);e!=nil{t.Fatal(e)}
    if out.State.Download!=8192||out.State.Get!=2||out.State.Write!=1||out.State.Upload!=1024{t.Fatal(out)}
    // Contention across independent PHP/Go processes, same sidecar lock.
    var wg sync.WaitGroup
    for i:=0;i<8;i++{wg.Add(2);go func(){defer wg.Done();if _,e:=q.reserveBudget("get",1024);e!=nil{t.Error(e)}}();go func(){defer wg.Done();php("reserve","get","1024")}()}
    wg.Wait()
    if e=json.Unmarshal(php("status"),&out);e!=nil{t.Fatal(e)}
    if out.State.Get!=18||out.State.Download!=24576{t.Fatal(out)}
    path:=filepath.Join(q.config.BudgetRoot,"state.json")
    out.State.Month="2000-01"
    raw,_:=json.Marshal(out.State)
    if e=os.WriteFile(path,raw,0600);e!=nil{t.Fatal(e)}
    php("reserve","get","4096")
    if e=json.Unmarshal(php("status"),&out);e!=nil{t.Fatal(e)}
    if out.State.Get!=1||out.State.Download!=4096||out.State.Upload!=1024||out.State.Write!=0{t.Fatal(out)}
    out.State.Write=18000;out.State.Download=2<<30
    raw,_=json.Marshal(out.State);if e=os.WriteFile(path,raw,0600);e!=nil{t.Fatal(e)}
    php("deny","get","1");php("deny","upload","1")
    if _,e=q.reserveBudget("get",1);e==nil{t.Fatal("Go exceeded download")}
    if _,e=q.reserveBudget("upload",1);e==nil{t.Fatal("Go exceeded ordinary writes")}
    if _,e=q.reserveBudget("cleanup",0);e!=nil{t.Fatal(e)}
    if e=os.Remove(path);e!=nil{t.Fatal(e)}
    php("deny","get","1")
    if _,e=q.reserveBudget("get",1);e==nil{t.Fatal("Go reset lost state")}
    fmt.Println("PASS cross-language concurrency, month rollover, cleanup reserve, loss protection")
}
