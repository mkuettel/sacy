<?php

namespace sacy\internal;

use sacy\Exception;
use sacy\ExternalProcessorRegistry;
use sacy\transforms\Minify_CSS_UriRewriter;
use sacy\transforms\PhpSassSacy;

class CssRenderHandler extends ConfiguredRenderHandler{
    private $to_process = [];
    private $collecting = false;
    private $cache_db = null;

    function __construct(BlockParams $cfg, $fragment_cache, $source_file) {
        parent::__construct($cfg, $fragment_cache, $source_file);
    }

    private function getDepcache(){
        if (!extension_loaded('pdo_sqlite')) return null;

        if ($this->cache_db === null) {
            $cache_file = defined('SACY_DEPCACHE_FILE')
                ? SACY_DEPCACHE_FILE
                : implode(DIRECTORY_SEPARATOR, array(
                    sys_get_temp_dir(),
                    sprintf('sacy-depcache-%s-v3.sqlite3', md5(ASSET_COMPILE_OUTPUT_DIR))
                ));

            $create_tables = !file_exists($cache_file);

            $pdo = new \PDO("sqlite:$cache_file");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            if ($create_tables) {
                $pdo->exec('create table depcache (source text not null, mtime_source integer not null, mtime integer not null, depends_on text not null, primary key (source, depends_on))');
                $pdo->exec('create index idx_source on depcache(source, mtime_source)');
            } else {
                $pdo = new \PDO("sqlite:$cache_file");
            }

            $this->cache_db = $pdo;
        }
        return $this->cache_db;
    }


    static function supportedTransformations(){
        $res = array('', 'text/css');
        if (class_exists('lessc') || ExternalProcessorRegistry::typeIsSupported('text/x-less'))
            $res[] = 'text/x-less';
        if (class_exists('SassParser') || ExternalProcessorRegistry::typeIsSupported('text/x-sass'))
            $res = array_merge($res, array('text/x-sass', 'text/x-scss'));
        if (PhpSassSacy::isAvailable())
            $res[] = 'text/x-scss';

        return array_unique($res);
    }

    function getFileExtension() { return '.css'; }

    static function willTransformType($type){
        // transforming everything but plain old CSS
        return !in_array($type, array('', 'text/css'));
    }

    function writeHeader($fh, $work_units){
        fwrite($fh, "/*\nsacy css cache dump \n\n");
        fwrite($fh, "This dump has been created from the following files:\n");
        foreach($work_units as $file){
            fprintf($fh, "    - %s\n", str_replace($this->getConfig()->get('server_params')['DOCUMENT_ROOT'], '<root>', $file['file']));
        }
        fwrite($fh, "*/\n\n");
    }

    function processFile($fh, $work_unit){
        // for now: only support collecting for scss and sass
        if (!in_array($work_unit['type'], array('text/x-scss', 'text/x-sass'))){
            $this->collecting = false;
        }
        if ($this->collecting){
            $content = @file_get_contents($work_unit['file']);
            if (!$content) $content = "/* error accessing file {$work_unit['file']} */";

            $content = Minify_CSS_UriRewriter::rewrite(
                $content,
                dirname($work_unit['file']),
                $this->getConfig()->get('server_params')['DOCUMENT_ROOT'],
                array(),
                true
            );

            $this->to_process[] = array(
                'file' => $work_unit['file'],
                'content' => $content,
                'type' => $work_unit['type'],
            );
        }else{
            if ($this->getConfig()->get('write_headers'))
               fprintf($fh, "\n/* %s */\n", str_replace($this->getConfig()->get('server_params')['DOCUMENT_ROOT'], '<root>', $work_unit['file']));

            fwrite($fh, $this->getOutput($work_unit));
        }
    }

    function endWrite($fh){
        if (!$this->collecting) return;
        $content = '';
        $incpath = [];
        foreach($this->to_process as $job){
            $content .= $job['content'];
            $incpath[] = dirname($job['file']);
        }

        fwrite($fh, $this->getOutput(array(
            'content' => $content,
            'type' => $this->to_process[0]['type'],
            'paths' => $incpath
        )));

    }

    function getOutput($work_unit){
        $debug = $this->getConfig()->getDebugMode() == 3;

        if ($work_unit['file']){
            $css = @file_get_contents($work_unit['file']);
            if (!$css) return "/* error accessing file */";
            $source_file = $work_unit['file'];
        }else{
            $css = $work_unit['content'];
            $source_file = $this->getSourceFile();
        }

        if (ExternalProcessorRegistry::typeIsSupported($work_unit['type'])){
            $opts = array();
            if ($work_unit['paths'])
                $opts['library_path'] = $work_unit['paths'];
            $opts['plugin_files'] = $this->getConfig()->get('sassc_plugins');
            $opts['env'] = $this->getConfig()->get('env');
            $opts['document_root'] = $this->getConfig()->get('server_params')['DOCUMENT_ROOT'];
            $css = ExternalProcessorRegistry::getTransformerForType($work_unit['type'])
                ->transform($css, $source_file, $opts);
        }else{
            if ($work_unit['type'] == 'text/x-less'){
                $less = new \lessc();
                $less->importDir = dirname($source_file).'/'; #lessphp concatenates without a /
                $css = $less->parse($css);
            }
            if (PhpSassSacy::isAvailable() && $work_unit['type'] == 'text/x-scss'){
                $css = PhpSassSacy::compile($source_file, $work_unit['paths'] ?: array(dirname($source_file)));
            }elseif (in_array($work_unit['type'], array('text/x-scss', 'text/x-sass'))){
                $config = array(
                    'cache' => false, // no need. WE are the cache!
                    'debug_info' => $debug,
                    'line' => $debug,
                    'load_paths' => $work_unit['paths'] ?: array(dirname($source_file)),
                    'filename' => $source_file,
                    'quiet' => true,
                    'style' => $debug ? 'nested' : 'compressed'
                );
                $sass = new \SassParser($config);
                $css = $sass->toCss($css, false); // isFile?
            }
        }

        if ($debug){
            return \Minify_CSS_UriRewriter::rewrite(
                $css,
                dirname($source_file),
                $this->getConfig()->get('server_params')['DOCUMENT_ROOT'],
                array()
            );
        }else{
            return \Minify_CSS::minify($css, array(
                'currentDir' => dirname($source_file),
                'docRoot' => $this->getConfig()->get('server_params')['DOCUMENT_ROOT'],
            ));
        }
    }

    private function extract_import_file($parent_type, $parent_file, $cssdata){
        $f = null;
        if (preg_match('#^\s*url\((["\'])([^\1]+)\1#', $cssdata, $matches)){
            $f = $matches[2];
        }elseif(preg_match('#^\s*(["\'])([^\1]+)\1#', $cssdata, $matches)){
            $f = $matches[2];
        }

        $path_info = pathinfo($parent_file);
        if (in_array($parent_type, ['text/x-scss', 'text/x-sass'])){
            if (!in_array(pathinfo($f, PATHINFO_EXTENSION), ['scss', 'sass', 'css']))
                $f .= ".".$path_info['extension'];

            $mixin = implode(DIRECTORY_SEPARATOR, [
                $path_info['dirname'],
                dirname($f),
                "_".basename($f)
            ]);

            if (is_file($mixin)){
                return $mixin;
            }

        }elseif($parent_type == 'text/x-less'){
            // less only inlines @import's of .less files (see: http://lesscss.org/#-importing)
            if (!preg_match('#\.less$', $f)) return null;
        }else{
            return null;
        }

        if ($f[0] == '/') {
            $f = $this->getConfig()->get('server_params')['DOCUMENT_ROOT']. $f;
        }else{
            $f = $path_info['dirname'] . DIRECTORY_SEPARATOR . $f;
        }
        return file_exists($f) ? realpath($f) : null;
    }

    private function findCachedDeps($file){
        $pdo = $this->getDepcache();
        if (!$pdo) return null;

        $sh = $pdo->prepare('select mtime, depends_on from depcache where source = ? and mtime_source >= ?');
        $sh->execute([$file, filemtime($file)]);

        $res = null;
        while(false != ($ra = $sh->fetch())){
            if ($ra['depends_on'] != ''){
                if (!file_exists($ra['depends_on'])){
                    return null;
                }
                if (filemtime($ra['depends_on']) != $ra['mtime']){
                    return null;
                }
                $res[] = $ra['depends_on'];
            }else{
                $res = [];
            }
        }
        return $res;
    }

    private function find_dependencies($type, $file, $level){
        $level++;
        if (!in_array($type, ['text/x-scss', 'text/x-sass', 'text/x-less']))
            return [];

        if ($level > 10) throw new Exception("CSS Include nesting level of $level too deep");

        if (!is_file($file)) return [];
        $normalized_file = realpath($file);

        $from_cache = $this->findCachedDeps($normalized_file);

        if ($from_cache !== null){
            return $from_cache;
        }

        $res = [];
        $fh = fopen($file, 'r');
        while(false !== ($line = fgets($fh))){
            if (preg_match('#^\s*$#', $line)) continue;
            if (preg_match('#^\s*@import(.*)$#', $line, $matches)){
                $f = $this->extract_import_file($type, $file, $matches[1]);
                if (!$f) continue;
                $f = realpath($f);
                if ($f){
                    $res[] = $f;
                    $res = array_merge($res, $this->find_dependencies($type, $f, $level));
                }
                continue;
            }
            if (preg_match('#\s+url\([\'"]?[^)]+\)#', $line, $matches)){
                $f = $this->extract_import_file($type, $file, $matches[0]);
                if ($f){
                    $res[] = $f;
                }
            }

        }
        fclose($fh);

        $pdo = $this->getDepcache();
        if ($pdo) {
            $pdo->beginTransaction();

            $ch = $pdo->prepare("delete from depcache where source = ? and mtime_source < ?");
            $ch->execute([$normalized_file, filemtime($normalized_file)]);

            $sh = $pdo->prepare("insert or replace into depcache (source, depends_on, mtime, mtime_source) values (?, ?, ?, ?)");
            if ($res == []) {
                // no dependencies beacon
                $sh->execute([$normalized_file, '', filemtime($normalized_file), filemtime($normalized_file)]);
            } else {
                $dh = $pdo->prepare("delete from depcache where source = ? and depends_on = ''");
                $dh->execute([$normalized_file]);
                foreach ($res as $r) {
                    $sh->execute([$normalized_file, $r, filemtime($r), filemtime($normalized_file)]);
                }
            }
            $pdo->commit();
        }

        return $res;
    }

    function getAdditionalFiles($work_unit) {
        $level = 0;

        return $this->find_dependencies($work_unit['type'], $work_unit['file'], $level);
    }

    function startWrite(){
        $this->to_process = [];
        $this->collecting = true;
    }
}
