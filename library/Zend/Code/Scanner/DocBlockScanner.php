<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2013 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\Code\Scanner;

use Zend\Code\Annotation\AnnotationManager;
use Zend\Code\NameInformation;

class DocBlockScanner implements ScannerInterface
{
    /**
     * @var bool
     */
    protected $isScanned = false;

    /**
     * @var string
     */
    protected $docComment = null;

    /**
     * @var NameInformation
     */
    protected $nameInformation = null;

    /**
     * @var AnnotationManager
     */
    protected $annotationManager = null;

    /**
     * @var string
     */
    protected $shortDescription = null;

    /**
     * @var string
     */
    protected $longDescription = '';

    /**
     * @var array
     */
    protected $tags = array();

    /**
     * @var array
     */
    protected $annotations = array();

    /**
     * @param  string $docComment
     * @param null|NameInformation $nameInformation
     */
    public function __construct($docComment, NameInformation $nameInformation = null)
    {
        $this->docComment      = $docComment;
        $this->nameInformation = $nameInformation;
    }

    /**
     * @return string
     */
    public function getShortDescription()
    {
        $this->scan();

        return $this->shortDescription;
    }

    /**
     * @return string
     */
    public function getLongDescription()
    {
        $this->scan();

        return $this->longDescription;
    }

    /**
     * @return array
     */
    public function getTags()
    {
        $this->scan();

        return $this->tags;
    }

    /**
     * @return array
     */
    public function getAnnotations()
    {
        $this->scan();

        return $this->annotations;
    }

    /**
     * @return void
     */
    protected function scan()
    {
        if ($this->isScanned) {
            return;
        }
        
        $str = trim($this->docComment);
        $str = str_replace(array("\r\n", "\r"), array("\n", "\n"), $str);
        
        // First let's strip out the comment characters.
        $lines = explode("\n", $str);
        foreach ($lines as &$line) {
           $line = preg_replace('`^/\*{2,} ?`', '', $line); // opening /**
           $line = preg_replace('` ?\*+/$`', '', $line); // closing */
           $line = preg_replace('`^\s*\* ?`', '', $line); // internal lines.
        }
        
        // Trim leading/trailing whitespace.
        $lines = explode("\n", trim(implode("\n", $lines)));
        $tags = array();
        $currentTagName = null;
        $currentTag = array();
        $longDescription = '';
        
        // Parse the lines.
        foreach ($lines as $line) {
           // Check for a tag.
           if (preg_match('`^\s*(@[a-z]+)\s*(.*)`i', $line, $matches)) {
              // Clear out the old tag.
              if ($currentTagName !== null) {
                 $tags[] = array(
                    'name' => $currentTagName,
                    'value' => implode("\n", $currentTag)
                    );
              }
              
              // Set the current tag.
              $currentTagName = $matches[1];
              $currentTag = array($matches[2]);
           } else {
              // This is a description. Either from a tag or the main docblock.
              if ($currentTagName !== null) {
                 $currentTag[] = $line;
              } elseif (!$this->shortDescription) {
                 $this->shortDescription = $line;
              } elseif ($longDescription) {
                 $longDescription .= "\n".$line;
              } else {
                 $longDescription = $line;
              }
           }
        }
        // Clear out the last tag.
        if ($currentTagName !== null) {
            $tags[] = array(
               'name' => $currentTagName,
               'value' => implode("\n", $currentTag)
               );
         }
           
        $this->shortDescription = trim($this->shortDescription);
        $this->longDescription  = trim($longDescription);
        $this->tags = $tags;
        $this->isScanned        = true;
    }

    /**
     * @return array
     */
    protected function tokenize()
    {
        static $CONTEXT_INSIDE_DOCBLOCK = 0x01;
        static $CONTEXT_INSIDE_ASTERISK = 0x02;

        $context     = 0x00;
        $stream      = $this->docComment;
        $streamIndex = null;
        $tokens      = array();
        $tokenIndex  = null;
        $currentChar = null;
        $currentWord = null;
        $currentLine = null;

        $MACRO_STREAM_ADVANCE_CHAR       = function ($positionsForward = 1) use (&$stream, &$streamIndex, &$currentChar, &$currentWord, &$currentLine) {
            $positionsForward = ($positionsForward > 0) ? $positionsForward : 1;
            $streamIndex      = ($streamIndex === null) ? 0 : $streamIndex + $positionsForward;
            if (!isset($stream[$streamIndex])) {
                $currentChar = false;

                return false;
            }
            $currentChar = $stream[$streamIndex];
            $matches     = array();
            $currentLine = (preg_match('#(.*)\n#', $stream, $matches, null,
                                       $streamIndex) === 1) ? $matches[1] : substr($stream, $streamIndex);
            if ($currentChar === ' ') {
                $currentWord = (preg_match('#( +)#', $currentLine, $matches) === 1) ? $matches[1] : $currentLine;
            } else {
                $currentWord = (($matches = strpos($currentLine, ' ')) !== false) ? substr($currentLine, 0, $matches) : $currentLine;
            }

            return $currentChar;
        };
        $MACRO_STREAM_ADVANCE_WORD       = function () use (&$currentWord, &$MACRO_STREAM_ADVANCE_CHAR) {
            return $MACRO_STREAM_ADVANCE_CHAR(strlen($currentWord));
        };
        $MACRO_STREAM_ADVANCE_LINE       = function () use (&$currentLine, &$MACRO_STREAM_ADVANCE_CHAR) {
            return $MACRO_STREAM_ADVANCE_CHAR(strlen($currentLine));
        };
        $MACRO_TOKEN_ADVANCE             = function () use (&$tokenIndex, &$tokens) {
            $tokenIndex          = ($tokenIndex === null) ? 0 : $tokenIndex + 1;
            $tokens[$tokenIndex] = array('DOCBLOCK_UNKNOWN', '');
        };
        $MACRO_TOKEN_SET_TYPE            = function ($type) use (&$tokenIndex, &$tokens) {
            $tokens[$tokenIndex][0] = $type;
        };
        $MACRO_TOKEN_APPEND_CHAR         = function () use (&$currentChar, &$tokens, &$tokenIndex) {
            $tokens[$tokenIndex][1] .= $currentChar;
        };
        $MACRO_TOKEN_APPEND_WORD         = function () use (&$currentWord, &$tokens, &$tokenIndex) {
            $tokens[$tokenIndex][1] .= $currentWord;
        };
        $MACRO_TOKEN_APPEND_WORD_PARTIAL = function ($length) use (&$currentWord, &$tokens, &$tokenIndex) {
            $tokens[$tokenIndex][1] .= substr($currentWord, 0, $length);
        };
        $MACRO_TOKEN_APPEND_LINE         = function () use (&$currentLine, &$tokens, &$tokenIndex) {
            $tokens[$tokenIndex][1] .= $currentLine;
        };

        $MACRO_STREAM_ADVANCE_CHAR();
        $MACRO_TOKEN_ADVANCE();

        TOKENIZER_TOP:

        if ($context === 0x00 && $currentChar === '/' && $currentWord === '/**') {
            $MACRO_TOKEN_SET_TYPE('DOCBLOCK_COMMENTSTART');
            $MACRO_TOKEN_APPEND_WORD();
            $MACRO_TOKEN_ADVANCE();
            $context |= $CONTEXT_INSIDE_DOCBLOCK;
            $context |= $CONTEXT_INSIDE_ASTERISK;
            if ($MACRO_STREAM_ADVANCE_WORD() === false) {
                goto TOKENIZER_END;
            }
            goto TOKENIZER_TOP;
        }

        if ($context & $CONTEXT_INSIDE_DOCBLOCK && $currentWord === '*/') {
            $MACRO_TOKEN_SET_TYPE('DOCBLOCK_COMMENTEND');
            $MACRO_TOKEN_APPEND_WORD();
            $MACRO_TOKEN_ADVANCE();
            $context &= ~$CONTEXT_INSIDE_DOCBLOCK;
            if ($MACRO_STREAM_ADVANCE_WORD() === false) {
                goto TOKENIZER_END;
            }
            goto TOKENIZER_TOP;
        }

        if ($currentChar === ' ' || $currentChar === "\t") {
            $MACRO_TOKEN_SET_TYPE(($context & $CONTEXT_INSIDE_ASTERISK) ? 'DOCBLOCK_WHITESPACE' : 'DOCBLOCK_WHITESPACE_INDENT');
            $MACRO_TOKEN_APPEND_WORD();
            $MACRO_TOKEN_ADVANCE();
            if ($MACRO_STREAM_ADVANCE_WORD() === false) {
                goto TOKENIZER_END;
            }
            goto TOKENIZER_TOP;
        }

        if ($currentChar === '*') {
            if (($context & $CONTEXT_INSIDE_DOCBLOCK) && ($context & $CONTEXT_INSIDE_ASTERISK)) {
                $MACRO_TOKEN_SET_TYPE('DOCBLOCK_TEXT');
            } else {
                $MACRO_TOKEN_SET_TYPE('DOCBLOCK_ASTERISK');
                $context |= $CONTEXT_INSIDE_ASTERISK;
            }
            $MACRO_TOKEN_APPEND_CHAR();
            $MACRO_TOKEN_ADVANCE();
            if ($MACRO_STREAM_ADVANCE_CHAR() === false) {
                goto TOKENIZER_END;
            }
            goto TOKENIZER_TOP;
        }

        if ($currentChar === '@') {
            $MACRO_TOKEN_SET_TYPE('DOCBLOCK_TAG');
            $MACRO_TOKEN_APPEND_WORD();
            $MACRO_TOKEN_ADVANCE();
            if ($MACRO_STREAM_ADVANCE_WORD() === false) {
                goto TOKENIZER_END;
            }
            goto TOKENIZER_TOP;
        }

        if ($currentChar === "\n") {
            $MACRO_TOKEN_SET_TYPE('DOCBLOCK_NEWLINE');
            $MACRO_TOKEN_APPEND_CHAR();
            $MACRO_TOKEN_ADVANCE();
            $context &= ~$CONTEXT_INSIDE_ASTERISK;
            if ($MACRO_STREAM_ADVANCE_CHAR() === false) {
                goto TOKENIZER_END;
            }
            goto TOKENIZER_TOP;
        }

        $MACRO_TOKEN_SET_TYPE('DOCBLOCK_TEXT');
        $MACRO_TOKEN_APPEND_LINE();
        $MACRO_TOKEN_ADVANCE();
        if ($MACRO_STREAM_ADVANCE_LINE() === false) {
            goto TOKENIZER_END;
        }
        goto TOKENIZER_TOP;

        TOKENIZER_END:

        array_pop($tokens);

        return $tokens;
    }
}