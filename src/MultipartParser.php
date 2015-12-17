<?php

namespace React\Http;

use function GuzzleHttp\Psr7\parse_header;

/**
 * Parse a multipart body
 *
 * Original source is from https://gist.github.com/jas-/5c3fdc26fedd11cb9fb5
 *
 * @author jason.gerfen@gmail.com
 * @author stephane.goetz@onigoetz.ch
 * @license http://www.gnu.org/licenses/gpl.html GPL License 3
 */
class MultipartParser
{
    /**
     * @var string
     */
    protected $input;

    /**
     * @var string
     */
    protected $boundary;

    /**
     * Contains the resolved posts
     *
     * @var array
     */
    protected $post = [];

    /**
     * Contains the resolved files
     *
     * @var array
     */
    protected $files = [];

    /**
     * @param $input
     * @param $boundary
     */
    public function __construct($input, $boundary)
    {
        $this->input = $input;
        $this->boundary = $boundary;
    }

    /**
     * @return array
     */
    public function getPost()
    {
        return $this->post;
    }

    /**
     * @return array
     */
    public function getFiles()
    {
        return $this->files;
    }

    /**
     * Do the actual parsing
     */
    public function parse()
    {
        $blocks = $this->split($this->boundary);

        foreach ($blocks as $value) {
            if (empty($value)) {
                continue;
            }

            $this->parseBlock($value);
        }
    }

    /**
     * @param $boundary string
     * @returns Array
     */
    protected function split($boundary)
    {
        $boundary = preg_quote($boundary);
        $result = preg_split("/\\-+$boundary/", $this->input);
        array_pop($result);
        return $result;
    }

    /**
     * Decide if we handle a file, post value or octet stream
     *
     * @param $string string
     * @returns void
     */
    protected function parseBlock($string)
    {
        if (strpos($string, 'filename') !== false) {
            $this->file($string);
            return;
        }

        // This may never be called, if an octet stream
        // has a filename it is catched by the previous
        // condition already.
        if (strpos($string, 'application/octet-stream') !== false) {
            $this->octetStream($string);
            return;
        }

        $this->post($string);
    }

    /**
     * Parse a raw octet stream
     *
     * @param $string
     * @return array
     */
    protected function octetStream($string)
    {
        preg_match('/name=\"([^\"]*)\".*stream[\n|\r]+([^\n\r].*)?$/s', $string, $match);

        $this->addResolved('post', $match[1], $match[2]);
    }

    /**
     * Parse a file
     *
     * @param $string
     */
    protected function file($string)
    {
        // Remove leading newlines
        $string = ltrim($string, "\r\n");

        if (false === strpos($string, "\r\n\r\n")) {
            return;
        }

        list($headers, $body) = explode("\r\n\r\n", $string, 2);

        $body = rtrim($body, "\r\n");

        $headers = $this->parseHeaders($headers);

        $contentType = $this->extractContentType($headers);
        $contentDispositionParts = $this->extractContentDisposition($headers);

        if (!isset($contentDispositionParts['name'])) {
            return;
        }

        $path = tempnam(sys_get_temp_dir(), 'php');
        $err = file_put_contents($path, $body);

        $data = [
            'name' => isset($contentDispositionParts['filename']) ? $contentDispositionParts['filename'] : null,
            'type' => $contentType,
            'tmp_name' => $path,
            'error' => ($err === false) ? UPLOAD_ERR_NO_FILE : UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];

        $this->addResolved('files', $contentDispositionParts['name'], $data);
    }

    /**
     * Parse POST values
     *
     * @param $string
     * @return array
     */
    protected function post($string)
    {
        preg_match('/name=\"([^\"]*)\"[\n|\r]+([^\n\r].*)?\r$/s', $string, $match);

        $this->addResolved('post', $match[1], $match[2]);
    }

    /**
     * Put the file or post where it belongs,
     * The key names can be simple, or containing []
     * it can also be a named key
     *
     * @param $type
     * @param $key
     * @param $content
     */
    protected function addResolved($type, $key, $content)
    {
        if (preg_match('/^(.*)\[(.*)\]$/i', $key, $tmp)) {
            if (!empty($tmp[2])) {
                $this->{$type}[$tmp[1]][$tmp[2]] = $content;
            } else {
                $this->{$type}[$tmp[1]][] = $content;
            }
        } else {
            $this->{$type}[$key] = $content;
        }
    }

    /**
     * @param string $headers
     * @return array
     */
    private function parseHeaders($headers)
    {
        $result = [];
        $headers = preg_split("/\r\n/", $headers);
        foreach ($headers as $header) {
            $header = preg_split('/\s*:\s*+/', $header, 2);
            $result[strtolower($header[0])][] = $header[1];
        }

        return $result;
    }

    private function extractContentType(array $headers)
    {
        return isset($headers['content-type'])
            ? $headers['content-type'][0]
            : null;
    }

    private function extractContentDisposition(array $headers)
    {
        return isset($headers['content-disposition'])
            ? parse_header($headers['content-disposition'][0])[0]
            : [];
    }
}
