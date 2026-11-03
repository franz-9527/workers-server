package avpipeline
import("context";"os";"os/exec";"path/filepath";"testing";"encoding/json")
func TestNativeWechatVoiceSampleRatesAreCanonicalized(t *testing.T){
 if os.Getenv("CHAT_AV_NATIVE_TEST")!="1"{t.Skip("independent offline fixture required")}
 python,e:=exec.LookPath("python3");if e!=nil{t.Fatal(e)};ffmpeg,e:=exec.LookPath("ffmpeg");if e!=nil{t.Fatal(e)};ffprobe,e:=exec.LookPath("ffprobe");if e!=nil{t.Fatal(e)}
 script,_:=filepath.Abs("../../helpers/media/inspect.py");root:=t.TempDir();h:=&Helper{python,script,ffmpeg,ffprobe,root}
 for _,test:=range []struct{rate,channels string}{{"8000","1"},{"16000","1"},{"44100","2"},{"48000","1"}}{t.Run(test.rate+"-"+test.channels,func(t *testing.T){
  input:=filepath.Join(root,"voice-"+test.rate+".mp3");cmd:=exec.Command(ffmpeg,"-v","error","-nostdin","-threads","1","-y","-f","lavfi","-i","sine=frequency=440:sample_rate="+test.rate,"-t","2","-ac",test.channels,"-c:a","libmp3lame","-b:a","48k",input)
  if out,e:=cmd.CombinedOutput();e!=nil{t.Fatal(e,string(out))}
  raw,e:=os.ReadFile(input);if e!=nil{t.Fatal(e)};out,e:=h.Process(context.Background(),raw,"audio","voice");if e!=nil{if d,ok:=e.(helperFailure);ok{t.Log(d.diagnostic,d.code)};t.Fatal(e)}
  if out.DurationMS<1900||out.DurationMS>2100||len(out.Segments)!=1||!out.HasAudio{t.Fatal("duration/segments",out.DurationMS)}
  target:=filepath.Join(root,"canonical-"+test.rate+".mp3");if e=os.WriteFile(target,out.Main,0600);e!=nil{t.Fatal(e)}
  result,e:=exec.Command(ffprobe,"-v","error","-show_entries","stream=sample_rate,channels","-of","json",target).Output();if e!=nil{t.Fatal(e)}
  var info struct{Streams []struct{Rate string `json:"sample_rate"`;Channels int `json:"channels"`} `json:"streams"`};if json.Unmarshal(result,&info)!=nil||len(info.Streams)!=1||info.Streams[0].Rate!="16000"||info.Streams[0].Channels!=1{t.Fatal("noncanonical output",string(result))}
 })}
}
