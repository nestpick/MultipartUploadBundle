<?php

namespace Nestpick\MultipartUploadBundle\EventListener;

use Nestpick\MultipartUploadBundle\Exception\MultipartProcessorException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;

class MultipartRequestListener
{
    protected $tempDir;

    /**
     * @param string $tempDir
     */
    public function __construct($tempDir)
    {
        $this->tempDir = $tempDir;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        try {
            $this->processRequest($event->getRequest());
        } catch (MultipartProcessorException $e) {
            $message = 'Bad Request';

            if ($e->getMessage()) {
                $message .= ': '. $e->getMessage();
            }

            $response = new Response($message, Response::HTTP_BAD_REQUEST);

            $event->setResponse($response);
        }
    }

    public function processRequest(Request $request)
    {
        $contentType = $request->headers->get('Content-Type');

        if (0 === strpos($contentType, 'multipart/related')) {
            list($onlyContentType, $boundary) = $this->getContentTypeAndBoundary($contentType);

            $parts = $this->getRequestParts($request, $boundary);
            $attachments = $request->attributes->get('attachments', []);

            foreach ($parts as $k => $part) {
                $parsed = $this->parsePart($part);

                $content = $parsed['content'];
                $mimeType = @$parsed['headers']['content-type'];
                $length = @$parsed['headers']['content-length'];
                $filename = @$parsed['headers']['file-name'];
                $formName = @$parsed['headers']['form-name'];
                $md5Sum = @$parsed['headers']['content-md5'];

                if ($k === 0) {
                    $request->headers->add($parsed['headers']);

                    if (!$mimeType) {
                        $request->headers->remove('Content-Type');
                    }

                    if ('application/x-www-form-urlencoded' === $mimeType) {
                        $output = [];
                        parse_str($content, $output);
                        $request->request->add($output);
                    } else {
                        $this->setRequestContent($request, $content);
                    }
                } else {
                    // Skip if MD5 is not matching
                    if (null !== $md5Sum && md5($content) !== strtolower($md5Sum)) {
                        var_dump(md5($content), strtolower($md5Sum));
                        $uploadError = 'MD5';
                    }

                    $tmpPath = $this->getTempFilename();
                    file_put_contents($tmpPath, $content);
                    $file = new UploadedFile($tmpPath, $filename ?: uniqid(), $mimeType, $length ?: strlen($content), @$uploadError, true);

                    if (isset($formName)) {
                        $formPath = $this->parseKey($formName);

                        $files = $request->files->all();
                        $files = $this->mergeFilesArray($files, $formPath, $file);
                        $request->files->replace($files);
                    } else {
                        // For Backwards-Compatibility v0.1
                        $request->attributes->set('_multipart_related_' . $k, $file);
                        $request->attributes->set('_multipart_related', $file);

                        $attachments[] = $file;
                    }
                }
            }

            $request->attributes->set('attachments', $attachments);
        }
    }

    private function setRequestContent(Request $request, $content)
    {
        $p = new \ReflectionProperty(Request::class, 'content');
        $p->setAccessible(true);
        $p->setValue($request, $content);
    }

    protected function mergeFilesArray($array, $path, $file)
    {
        if (count($path) > 0) {
            $key = array_shift($path);

            if (!is_array($array)) {
                $array = [];
            }

            if (!empty($key)) {
                $array[$key] = $this->mergeFilesArray(@$array[$key] ?: [], $path, $file);
            } else {
                $array[] = $file;
            }

            return $array;
        }

        return $file;
    }

    protected function parseKey($key)
    {
        return array_map(
            function($v) {
                return trim($v, ']');
            },
            explode('[', $key)
        );
    }

    /**
     * @param string $content
     *
     * @throws MultipartProcessorException
     *
     * @return array
     */
    protected function parsePart($content)
    {
        if (empty($content)) {
            throw new MultipartProcessorException('An empty content part found');
        }

        $part = $this->splitHeadersFromContent($content);

        $headers = $this->parseHeadersContent($part['headers']);

        return [
            'headers' => $headers,
            'content' => $part['content']
        ];
    }

    /**
     * @param string $headersContent
     *
     * @return array
     */
    protected function parseHeadersContent($headersContent)
    {
        $headers = [];

        foreach (explode(PHP_EOL, $headersContent) as $header) {
            $parts = explode(':', $header);
            if (count($parts) !== 2) {
                continue;
            }

            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            $headers[$name] = $value;

            if ('content-disposition' === strtolower($name)) {
                preg_match('/(?:^|form-data;\s*)name="?([^";]+)("|;|$)/', $value, $matches);
                if (isset($matches[1])) {
                    $headers['form-name'] = trim($matches[1]);
                }

                preg_match('/filename="?([^";]+)("|;|$)/', $value, $matches);
                if (isset($matches[1])) {
                    $headers['file-name'] = urldecode(trim($matches[1]));
                }
            }
        }

        return $headers;
    }

    /**
     * @param string $part
     * @return array
     */
    protected function splitHeadersFromContent($part)
    {
        if (strlen($part) <= 3) {
            throw new MultipartProcessorException('Unable to determine headers limit');
        }

        if (preg_match('/^[\r\n]/', $part)) {
            $split = 0;
        } else {
            preg_match('/(\r\n\r\n|\r\r|\n\n)/', $part, $matches, PREG_OFFSET_CAPTURE);
            if (!isset($matches[0][1])) {
                throw new MultipartProcessorException('Unable to determine headers limit');
            }

            $split = $matches[0][1];
        }

        $headersContent = trim(substr($part, 0, $split));
        $content = trim(substr($part, $split), "\r\n");

        return [
            'headers' => $headersContent,
            'content' => $content
        ];
    }

    /**
     * Get part of a resource.
     *
     * @param Request $request
     * @param $boundary
     *
     * @throws MultipartProcessorException
     *
     * @return array
     */
    protected function getRequestParts(Request $request, $boundary)
    {
        if (strlen($request->getContent()) == 0) {
            throw new MultipartProcessorException('An empty body received');
        }

        $contentHandler = $request->getContent(true);

        $delimiter = '--' . $boundary;
        $endDelimiter = '--' . $boundary . '--';
        $boundaryCount = 0;
        $parts = [];

        while (!feof($contentHandler)) {
            $line = fgets($contentHandler);
            if (false === $line) {
                throw new MultipartProcessorException('An error appears while reading input');
            }

            if (0 === $boundaryCount) {
                if (rtrim($line, "\r\n") !== $delimiter) {
                    if (ftell($contentHandler) === strlen($line)) {
                        throw new MultipartProcessorException('Expected boundary delimiter');
                    }
                } else {
                    continue;
                }
                ++$boundaryCount;
            } elseif (rtrim($line, "\r\n") === $delimiter) {
                ++$boundaryCount;
                continue;
            } elseif (rtrim($line, "\r\n") === $endDelimiter) {
                break;
            }

            if (!isset($parts[$boundaryCount])) {
                $parts[$boundaryCount] = '';
            }

            $parts[$boundaryCount] .= $line;
        }

        if (!$parts) {
            throw new MultipartProcessorException('An error appears while reading input');
        }

        return array_values($parts);
    }

    /**
     * Parse the content type and boundary from Content-Type header.
     *
     * @param string $contentType
     *
     * @throws MultipartProcessorException
     *
     * @return array
     */
    protected function getContentTypeAndBoundary($contentType)
    {
        $contentParts = explode(';', $contentType);
        if (2 !== count($contentParts)) {
            throw new MultipartProcessorException('Boundary may be missing');
        }

        $contentType = trim($contentParts[0]);
        $boundaryPart = trim($contentParts[1]);

        $shouldStart = 'boundary=';
        if (substr($boundaryPart, 0, strlen($shouldStart)) !== $shouldStart) {
            throw new MultipartProcessorException('Boundary is not set');
        }

        $boundary = substr($boundaryPart, strlen($shouldStart));
        if ('"' === substr($boundary, 0, 1) && '"' === substr($boundary, -1)) {
            $boundary = substr($boundary, 1, -1);
        }

        return [$contentType, $boundary];
    }

    /**
     * @return string
     */
    private function getTempFilename()
    {
        return $this->tempDir . DIRECTORY_SEPARATOR . 'MultipartRequestListener-' . sha1(uniqid('', true));
    }
}
