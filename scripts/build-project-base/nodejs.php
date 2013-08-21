#!/usr/bin/env php
<?php
// 每次 post-commit 之後，將 package.json 內的東西先抓好
// 包成一個 tar gz ，之後就解開 tar gz 就好了，速度超快!
// 
// 每次 post-commit 時
// 1. 先找 /srv/chroot/1000~1999 之間是否有可以用的 chroot
// 2. 找到後，在 /srv/chroot/1xxx.lock 加上 lock 避免衝突
// 3. 把 /srv/code/images/lang-nodejs.tar.gz 解壓縮進 chroot 中
// 4. 把所有檔案的修改日期都改成 2000/01/01 ，以方便作完 npm 可以找出新增加的檔
// 5. 把需要的 package.json 搬到 /srv/chroot/1xxx/ 下面
// 6. 找出新裝的東西，搬出來放進 tar gz 中，完工
//
// 之後要 clone 的時候
// 1. 把 tar gz 解開就好了
//
// 用法  php nodejs.php [package.json file]

class Prebuilder
{
    public function error($message)
    {
        die($message);
    }

    public function findAvailableRootAndLock()
    {
        $shuffled_roots = glob('/srv/chroot/1???');
        shuffle($shuffled_roots);
        foreach ($shuffled_roots as $root) {
            $lock_file = "{$root}.lock";

            if (file_exists("{$root}.initing") or file_exists("{$root}.used")) {
                continue;
            }

            $fp = fopen($lock_file, 'w+');
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                continue;
            }

            $this->fp = $fp;
            return $root;
        }

        return $this->error("No avaiabled root");
    }

    public function main($package_file, $force_rebuild = false)
    {
        if (0 !== posix_getuid()) {
            return $this->error("Muse be root");
        }

        if (!file_exists($package_file)) {
            return $this->error("File not found");
        }

        $project_file = '/tmp/project-nodejs-' . md5_file($package_file);

        // 已經有了，不需要再做了
        if (!$force_rebuild and file_exists($project_file . '.tar.gz')) {
            return;
        }

        // 先找可以用的 chroot
        error_log('find available root...');
        $root = $this->findAvailableRootAndLock();

        error_log('untar lang-mixed.tar.gz...');
        // 把 mixed package 解進去
        system("tar zxf /srv/code/images/lang-mixed.tar.gz --directory={$root}");
        exec("dpkg --root=${root} -i /srv/code/images/dev/*.deb");
        copy("{$root}/usr/bin/gcc", "{$root}/usr/bin/cc");

        error_log('find diff file...');
        // 把新解進去的檔案都改成 2001/1/1
        system("find {$root} -printf '%TY%Tm%Td %p\n' | grep -v '^20000101' | awk '{print $2}' | xargs -n 100 touch --no-dereference --date=20000101");

        error_log('npm installing ...');
        // 安裝 nodejs package
        chdir("{$root}");
        system("cp $package_file {$root}/srv/package.json");
        system("chroot {$root} sh -c \"cd /srv; npm install\"");
        system("rm -rf {$root}/root/package.json");

        // 把檔案弄進去
        chdir($root);

        error_log('build tar.gz...');
        // TODO: 處理過程中會不會處理到一半的檔案被人拿走...
        // 處理檔案
        system("find . -type f -printf \"%TY%Tm%Td,,,%p\n\" | grep -v '^20000101' | awk -F,,, '{print \"\\\"\"$2\"\\\"\"}' | xargs -n 100 tar -uf " . escapeshellarg($project_file . '.tar'));
        // 處理 symbolic link
        system("find . -type l -printf \"%TY%Tm%Td,,,%p\n\" | grep -v '^20000101' | awk -F,,, '{print \"\\\"\"$2\"\\\"\"}' | xargs -n 100 tar -uf " . escapeshellarg($project_file . '.tar'));

        system("gzip {$project_file}.tar");

        // 標示為已用完，並解除 lock
        touch("{$root}.used");

        flock($this->fp, LOCK_UN);    // release the lock
        fclose($this->fp);
        unlink("{$root}.lock");
    }
}

$p = new Prebuilder;
$p->main($_SERVER['argv'][1], array_key_exists(2, $_SERVER['argv']) ? true : false);
