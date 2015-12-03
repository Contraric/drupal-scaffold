<?php
namespace DrupalComposer\DrupalScaffold;

use Robo\Result;
use Robo\Task\BaseTask;

/**
 * Extracts an archive.
 *
 * ``` php
 * <?php
 * $this->taskExtract($archivePath)
 *  ->to($destination)
 *  ->run();
 * ?>
 * ```
 *
 * @method to(string) location to store extracted files
 */
class Extract extends BaseTask
{
    use \Robo\Common\DynamicParams;

    protected $filename;
    protected $to;

    public function __construct($filename)
    {
        $this->filename = $filename;
    }

    function run()
    {
        if (!file_exists($this->filename)) {
            $this->printTaskError("File {$this->filename} does not exist");
            return false;
        }

        $text = file_get_contents($this->filename);
        if ($this->regex) {
            $text = preg_replace($this->regex, $this->to, $text, -1, $count);
        } else {
            $text = str_replace($this->from, $this->to, $text, $count);
        }
        if ($count > 0) {
            $res = file_put_contents($this->filename, $text);
            if ($res === false) {
                return Result::error($this, "Error writing to file {$this->filename}.");
            }
            $this->printTaskSuccess("<info>{$this->filename}</info> updated. $count items replaced");
        } else {
            $this->printTaskInfo("<info>{$this->filename}</info> unchanged. $count items replaced");
        }
        return Result::success($this, '', ['replaced' => $count]);
    }

  function run() {
    if (!file_exists($this->filename)) {
      $this->printTaskError("File {$this->filename} does not exist");
      return false;
    }
    if (!($mimetype = static::archiveType($this->filename))) {
      $this->printTaskError("Could not determine type of archive for {$this->filename}");
      return false;
    }

    // We will first extract to $extractLocation and then move to $this->to
    $extractLocation = static::getTmpDir();
    $this->taskFilesystemStack()
      ->mkdir($extractLocation)
      ->mkdir(dirname($this->to))
      ->run();

    // Perform the extraction of a zip file.
    if (($mimetype == 'application/zip') || ($mimetype == 'application/x-zip')) {
      $this->taskExec("unzip")
        ->args($this->filename)
        ->args("-d")
        ->args("--destination=$extractLocation")
        ->run();
    }
    // Otherwise we have a possibly-compressed Tar file.
    // If we are not on Windows, then try to do "tar" in a single operation.
    else {
      $tar_compression_flag = '';
      if ($mimetype == 'application/x-gzip') {
        $tar_compression_flag = 'z';
      }
      elseif ($mimetype == 'application/x-bzip2') {
        $tar_compression_flag = 'j';
      }
      $this->taskExec("tar")
        ->args('-C')
        ->args($extractLocation)
        ->args("-x${$tar_compression_flag}f")
        ->args($this->filename)
        ->run();
    }

    // Now, we want to move the extracted files to $this->to. There
    // are two possibilities that we must consider:
    //
    // (1) Archived files were encapsulated in a folder with an arbitrary name
    // (2) There was no encapsulating folder, and all the files in the archive
    //     were extracted into $extractLocation
    //
    // In the case of (1), we want to move and rename the encapsulating folder
    // to $this->to.
    //
    // In the case of (2), we will just move and rename $extractLocation.
    $filesInExtractLocation = glob("$extractLocation/*");
    $hasEncapsulatingFolder = ((count($filesInExtractLocation) == 1) && is_dir($filesInExtractLocation[0]));
    if ($hasEncapsulatingFolder) {
      $this->taskFilesystemStack()
        ->rename($filesInExtractLocation[0], $this->to);
      $this->taskDeleteDir($extractLocation)->run();
    }
    else {
      $this->taskFilesystemStack()
        ->rename($extractLocation, $this->to);
    }
    return $return;
  }

  protected static function archiveType($archivePath) {
    $content_type = FALSE;
    if (class_exists('finfo')) {
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $content_type = $finfo->file($filename);
      // If finfo cannot determine the content type, then we will try other methods
      if ($content_type == 'application/octet-stream') {
        $content_type = FALSE;
      }
    }
    // Examing the file's magic header bytes.
    if (!$content_type) {
      if ($file = fopen($filename, 'rb')) {
        $first = fread($file, 2);
        fclose($file);

        if ($first !== FALSE) {
          // Interpret the two bytes as a little endian 16-bit unsigned int.
          $data = unpack('v', $first);
          switch ($data[1]) {
            case 0x8b1f:
              // First two bytes of gzip files are 0x1f, 0x8b (little-endian).
              // See http://www.gzip.org/zlib/rfc-gzip.html#header-trailer
              $content_type = 'application/x-gzip';
              break;

            case 0x4b50:
              // First two bytes of zip files are 0x50, 0x4b ('PK') (little-endian).
              // See http://en.wikipedia.org/wiki/Zip_(file_format)#File_headers
              $content_type = 'application/zip';
              break;

            case 0x5a42:
              // First two bytes of bzip2 files are 0x5a, 0x42 ('BZ') (big-endian).
              // See http://en.wikipedia.org/wiki/Bzip2#File_format
              $content_type = 'application/x-bzip2';
              break;
          }
        }
      }
    }
    // 3. Lastly if above methods didn't work, try to guess the mime type from
    // the file extension. This is useful if the file has no identificable magic
    // header bytes (for example tarballs).
    if (!$content_type) {
      // Remove querystring from the filename, if present.
      $filename = basename(current(explode('?', $filename, 2)));
      $extension_mimetype = array(
        '.tar.gz'  => 'application/x-gzip',
        '.tgz'     => 'application/x-gzip',
        '.tar'     => 'application/x-tar',
      );
      foreach ($extension_mimetype as $extension => $ct) {
        if (substr($filename, -strlen($extension)) === $extension) {
          $content_type = $ct;
          break;
        }
      }
    }
    return $content_type;
  }

  protected static function getTmpDir() {
    return getcwd() . '/tmp' . rand() . time();
  }

}
