<?php
header('Content-Type: application/json');

require_once '../lib/config.php';
require_once '../lib/db.php';
require_once '../lib/log.php';
require_once '../lib/applyEffects.php';
require_once '../lib/collage.php';
require_once '../lib/collageConfig.php';

function takeVideo($filename) {
    global $config;
    $cmd = sprintf($config['take_video']['cmd'], $filename);
    $cmd .= ' 2>&1'; //Redirect stderr to stdout, otherwise error messages get lost.

    exec($cmd, $output, $returnValue);

    if ($returnValue) {
        $ErrorData = [
            'error' => 'Take video command returned an error code',
            'cmd' => $cmd,
            'returnValue' => $returnValue,
            'output' => $output,
            'php' => basename($_SERVER['PHP_SELF']),
        ];
        $ErrorString = json_encode($ErrorData);
        logError($ErrorData);
        die($ErrorString);
    }

    $i = 0;
    $processingTime = 300;
    while ($i < $processingTime) {
        if (file_exists($filename)) {
            break;
        } else {
            $i++;
            usleep(100000);
        }
    }

    if (!file_exists($filename)) {
        $ErrorData = [
            'error' => 'File was not created',
            'cmd' => $cmd,
            'returnValue' => $returnValue,
            'output' => $output,
            'php' => basename($_SERVER['PHP_SELF']),
        ];
        // remove all files that were created - all filenames start with the videos name
        exec('rm -f ' . $filename . '*');
        $ErrorString = json_encode($ErrorData);
        logError($ErrorData);
        die($ErrorString);
    }

    $images = [];
    for ($i = 1; $i < 99; $i++) {
        $imageFilename = sprintf('%s-%02d.jpg', $filename, $i);
        if (file_exists($imageFilename)) {
            $images[] = $imageFilename;
        } else {
            break;
        }
    }

    $imageFolder = $config['foldersAbs']['images'] . DIRECTORY_SEPARATOR;
    $thumbsFolder = $config['foldersAbs']['thumbs'] . DIRECTORY_SEPARATOR;

    // If the video command created 4 images, create a cuttable collage (more flexibility to maybe come one day)
    if ($config['video']['collage'] && count($images) === 4) {
        $collageFilename = sprintf('%s-collage.jpg', $filename);
        $collageConfig = new CollageConfig();
        $collageConfig->collageLayout = '2x4-3';
        $collageConfig->collageTakeFrame = 'off';
        $collageConfig->collagePlaceholder = false;
        if (!createCollage($images, $collageFilename, $config['filters']['defaults'], $collageConfig)) {
            $errormsg = basename($_SERVER['PHP_SELF']) . ': Could not create collage';
            logErrorAndDie($errormsg);
        }
        $images[] = $collageFilename;
    }

    foreach ($images as $file) {
        $imageResource = imagecreatefromjpeg($file);
        $thumb_size = substr($config['picture']['thumb_size'], 0, -2);
        $thumbResource = resizeImage($imageResource, $thumb_size, $thumb_size);
        imagejpeg($thumbResource, $thumbsFolder . basename($file), $config['jpeg_quality']['thumb']);
        imagedestroy($thumbResource);
        $newFile = $imageFolder . basename($file);
        compressImage($config, false, $imageResource, $file, $newFile);
        if (!$config['picture']['keep_original']) {
            unlink($file);
        }
        imagedestroy($imageResource);
        if ($config['database']['enabled']) {
            appendImageToDB(basename($newFile));
        }
        $picture_permissions = $config['picture']['permissions'];
        chmod($newFile, octdec($picture_permissions));
    }
}

$random = md5(time()) . '.mp4';

if (!empty($_POST['file']) && preg_match('/^[a-z0-9_]+\.(mp4)$/', $_POST['file'])) {
    $name = $_POST['file'];
} elseif ($config['picture']['naming'] === 'numbered') {
    if ($config['database']['enabled']) {
        $images = getImagesFromDB();
    } else {
        $images = getImagesFromDirectory($config['foldersAbs']['images']);
    }
    $img_number = count($images);
    $files = str_pad(++$img_number, 4, '0', STR_PAD_LEFT);
    $name = $files . '.mp4';
} elseif ($config['picture']['naming'] === 'dateformatted') {
    $name = date('Ymd_His') . '.mp4';
} else {
    $name = $random;
}

if ($config['database']['file'] === 'db' || (!empty($_POST['file']) && preg_match('/^[a-z0-9_]+\.(mp4)$/', $_POST['file']))) {
    $file = $name;
} else {
    $file = $config['database']['file'] . '_' . $name;
}

$filename_tmp = $config['foldersAbs']['tmp'] . DIRECTORY_SEPARATOR . $file;
$filename_random = $config['foldersAbs']['tmp'] . DIRECTORY_SEPARATOR . $random;

if (file_exists($filename_tmp)) {
    rename($filename_tmp, $filename_random);
}

takeVideo($filename_tmp);

$LogData = [
    'success' => 'image',
    'file' => $file,
    'php' => basename($_SERVER['PHP_SELF']),
];

// send imagename to frontend
$LogString = json_encode($LogData);
if ($config['dev']['loglevel'] > 1) {
    logError($LogData);
}
die($LogString);
