package message
import "testing"
type namespacedStorage struct{}
func(namespacedStorage)Namespace()string{return "test/conversations"}
func TestMediaNamespaceProducesSeparateImageAndAVDirectories(t *testing.T){
 for _,kind:=range []string{"images","av"}{
  got:=mediaKeyPrefix(namespacedStorage{},"test",kind)
  if got!="test/conversations/"+kind+"/test/"{t.Fatal(got)}
 }
}
