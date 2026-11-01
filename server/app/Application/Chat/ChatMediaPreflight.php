<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

/** Offline probes run only from the managed CLI. Administrator GET only reads the result. */
final class ChatMediaPreflight
{
    public static function phpBinary():string
    {
        if(PHP_SAPI==='cli')return realpath(PHP_BINARY)?:PHP_BINARY;
        // PHP_BINARY points at php-fpm under FPM; its sibling is the CLI for the same version.
        foreach([PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,PHP_BINDIR.'/php',dirname(PHP_BINARY).'/php'] as $path){
            if(is_file($path)&&is_executable($path))return realpath($path)?:$path;
        }
        return '';
    }

    public function inspect(string $root,string $php,string $python,string $ffmpeg,string $ffprobe,bool $prepare):array
    {
        $script=$root.'/chat/helpers/preflight.py';
        $paths=[$php,$python,$ffmpeg,$ffprobe,$script,$root.'/server/bin/chat-images.php',$root.'/chat/helpers/images/inspect.py',$root.'/chat/helpers/media/inspect.py'];
        $facts=[];
        foreach($paths as $path){clearstatcache(true,$path);$facts[]=[$path,@filemtime($path),@filesize($path),is_executable($path)];}
        $fingerprint=hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR));
        $cache=$root.'/chat/bin/state/media-preflight.json';
        $empty=['php_cli'=>false,'php_gd'=>false,'pillow'=>false,'zxing'=>false,'ffmpeg'=>false,'ffprobe'=>false,'checked_at'=>0];
        if(!$prepare||PHP_SAPI!=='cli'){
            clearstatcache(true,$cache);
            if(is_link($cache)||!is_file($cache)||filesize($cache)>4096)return $empty;
            $saved=json_decode((string)@file_get_contents($cache),true);
            if(!is_array($saved)||($saved['fingerprint']??'')!==$fingerprint)return $empty;
            $result=$empty;
            foreach($empty as $key=>$value)$result[$key]=$key==='checked_at'?(int)($saved[$key]??0):($saved[$key]??false)===true;
            return $result;
        }
        $result=$empty;
        $phpResult=$this->run([$php,'-r','echo json_encode(["php_cli"=>PHP_SAPI==="cli","php_gd"=>extension_loaded("gd")&&function_exists("imagewebp")&&function_exists("imagejpeg")&&function_exists("imagepng")]);'],3);
        if(is_array($phpResult))foreach(['php_cli','php_gd'] as $key)$result[$key]=($phpResult[$key]??false)===true;
        $pythonResult=$this->run([$python,$script,$ffmpeg,$ffprobe],10);
        if(is_array($pythonResult))foreach(['pillow','zxing','ffmpeg','ffprobe'] as $key)$result[$key]=($pythonResult[$key]??false)===true;
        $result['checked_at']=time();
        // No credentials, provider requests or user input are part of this cache.
        $tmp=$cache.'.'.bin2hex(random_bytes(6)).'.tmp';
        if(!is_link($cache)&&is_dir(dirname($cache))){
            $json=json_encode($result+['fingerprint'=>$fingerprint],JSON_THROW_ON_ERROR);
            if(@file_put_contents($tmp,$json,LOCK_EX)===strlen($json)){@chmod($tmp,0640);if(!@rename($tmp,$cache))@unlink($tmp);}
            else @unlink($tmp);
        }
        return $result;
    }

    private function run(array $command,int $seconds):?array
    {
        if(!function_exists('proc_open')||!function_exists('proc_get_status')||!function_exists('proc_terminate')
            ||empty($command[0])||$command[0][0]!=='/'||!is_file($command[0])||!is_executable($command[0]))return null;
        $pipes=[];$process=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process))return null;
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);
        $deadline=microtime(true)+$seconds;$output='';$exit=-1;
        try{
            do{
                $output.=(string)stream_get_contents($pipes[1],4097-strlen($output));
                $status=proc_get_status($process);
                if(strlen($output)>4096||microtime(true)>=$deadline){proc_terminate($process,9);return null;}
                if(!$status['running']){$exit=(int)$status['exitcode'];break;}
                usleep(20000);
            }while(true);
            $output.=(string)stream_get_contents($pipes[1],4097-strlen($output));
            if($exit!==0||strlen($output)>4096)return null;
            $decoded=json_decode($output,true);return is_array($decoded)?$decoded:null;
        }finally{fclose($pipes[1]);proc_close($process);}
    }
}
