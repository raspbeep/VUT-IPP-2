<?php
////
// Copyright 2022
//
// @file interpret.py
// @author xkrato61 Pavel Kratochvil
//
// @brief Interprets IPPcode22 from input XML.
//
////

// directory error
const ERROR_DIRECTORY = 41;
// directory error parsing parameter --directory
const ERROR_DIR_DIRECTORY = 41;
// jexampath path give in param --jexamscript does not exist
const ERROR_JEXAMPATH = -4;
// parse script path give in param --parse-script does not exist
const ERROR_PARSE_PATH = -5;
// testing files' directory given in param --directory does not exist
const ERROR_TEST_PATH = -6;

// TODO: change for submission
const PHP_ALIAS = "php";
const PYTHON_ALIAS = "python3";
const JAVA_JAR_ALIAS = "java -jar";

const ERROR_PARSER = -7;

const ERROR_INTERPRET = -8;

const ERROR_COLLIDING_PARAMS = 10;


class InputArguments {

    public string $testDirectory = "./test/";
    public string $parseScriptPath = "./parse.php";
    public string $interpretScriptPath = "./interpret.py";
    public string $jexamPath = "/pub/courses/ipp/jexamxml/jexamxml.jar";
    // TODO: change to false
    public bool $recursion = true;
    public bool $parseOnly = false;
    public bool $interpretOnly = false;
    public bool $testInterpret = true;
    public bool $testParser = true;

    public bool $cleanFiles = false;
    // TODO: check argument collisions
    // TODO: change to .src
    // TODO: solve the fucking parser output shit
    // if --parse-only is set, reference output to compare parser xml output with is .out instead of .in
    public string $testingOutput = ".out";

    public bool $debug = false;

    public function __construct() {
        $argOptions = array("help", "directory:", "recursive", "parse-script:", "int-script:", "parse-only", "int-only", "jexampath:", "no-clean", "debug");
        $givenParams = getopt("", $argOptions);

        global $argc;

        if (array_key_exists("help", $givenParams)) {
            if ($argc == 2) {
                fputs(STDOUT, "
                    Usage: php test.php [--help] [--directory=path] [--recursive] [--parse-script=path]
                                        [--int-script=path] [--parse-only] [--int-only] [--jexampath=path]
                                        [--no-clean] [--debug]
                                        
                        help - print this message
                        directory       - path to test files directory, defaults to `./`
                        recursive       - looks for test files recursively in all subdirectories
                        parse-script    - path to parse.php script, defaults to `./parse.php` 
                        int-script      - path to interpret.py script, defaults to `./interpret.py`
                        parse-only      - test only parser.php script
                                          (cannot collide with --int-only, --int-script)
                        int-only        - test only interpret.py script 
                                          (cannot collide with --parse-only, --parse-script, --jexampath)
                        jexampath       - path to jexamxml.jar and corresponding `options` configuration file
                        no-clean        - intermediate files (e.g. output XML from script parse.php) won't be deleted
                        debug           - print debugging messages during execution\n");

                exit(0);
            } else {
                handleError(ERROR_PARAM);
            }
        }

        if (array_key_exists("directory", $givenParams)) {
            $directory = $givenParams["directory"];
            if (!str_ends_with($directory, "/")) {
                handleError(ERROR_DIR_DIRECTORY);
            }
            if (!file_exists($directory)) {
                handleError(ERROR_TEST_PATH);
            }

            $this->testDirectory = $givenParams["directory"];
        }

        if (array_key_exists("recursive", $givenParams)) {
            $this->recursion = true;
        }

        if (array_key_exists("parse-script", $givenParams)) {
            $parseScriptPath = $givenParams["parse-script"];
            if (!file_exists($parseScriptPath)) {
                handleError(ERROR_PARSE_PATH);
            }
            $this->parseScriptPath = $givenParams["parse-script"];
        }

        if (array_key_exists("int-only", $givenParams)) {
            if (array_key_exists("parse-only", $givenParams) || array_key_exists("parse-script", $givenParams)
                || array_key_exists("jexampath", $givenParams)) {
                handleError(ERROR_COLLIDING_PARAMS);
            }
            $this->testParser = false;
            $this->interpretOnly = true;
        }

        if (array_key_exists("parse-only", $givenParams)) {
            if (array_key_exists("int-only", $givenParams) || array_key_exists("int-script", $givenParams)) {
                handleError(ERROR_COLLIDING_PARAMS);
            }
            $this->testInterpret = false;
            $this->parseOnly = true;
            // TODO wtf is this
            $this->testingOutput = ".out";
        }

        if (array_key_exists("jexampath", $givenParams)) {
            $this->jexamPath = $givenParams["jexampath"];
            if (!str_ends_with($this->jexamPath, "/")) {
                $this->jexamPath .= "/";
            }
            $this->jexamPath .= "jexamxml.jar";
            if (!file_exists($this->jexamPath)) {
                handleError(ERROR_JEXAMPATH);
            }
        }

        if (array_key_exists("no-clean", $givenParams)) {
            $this->cleanFiles = false;
        }

        if (array_key_exists("debug", $givenParams)) {
            $this->debug = true;
        }

        // TODO check if any unknown parameters are present
        return 0;
    }
}

class Tester {
    private InputArguments $args;
    public htmlPrinter $htmlPrinter;
    private array $testCases;

    public function __construct() {
        $this->args = new InputArguments();


        $this->testCases = array();
        $this->findTests();
        $this->testParser();
        $this->testInterpret();

        $this->htmlPrinter = new htmlPrinter($this->testCases, $this->args);
        $this->tempFilesCleanUp();
    }

    public function tempFilesCleanUp() {
        if ($this->args->cleanFiles) {
            foreach ($this->testCases as $testCase) {
                if (!file_exists($testCase->pathToTest . ".stdout_par.tmp")) {
                    unlink($testCase->pathToTest . ".stdout_par.tmp");
                }
                if (!file_exists($testCase->pathToTest . ".stderr_par.tmp")) {
                    unlink($testCase->pathToTest . ".stderr_par.tmp");
                }
                if (!file_exists($testCase->pathToTest . ".stdout_int.tmp")) {
                    unlink($testCase->pathToTest . ".stdout_int.tmp");
                }
                if (!file_exists($testCase->pathToTest . "..stderr_int.tmp")) {
                    unlink($testCase->pathToTest . ".stderr_int.tmp");
                }
            }
        }
    }

    public function findTests() {
        // Construct the iterator
        $it = new RecursiveDirectoryIterator($this->args->testDirectory);

        // Loop through files
        foreach (new RecursiveIteratorIterator($it) as $file) {
            $fileName = $file->getFilename();
            // skip current and parent directory to avoid looping
            if ($fileName == "." || $fileName == "..") continue;

            $filePath = $file->getPath() . "/";
            $fileNoExt = preg_replace('/\\.[^.\\s]{2,4}$/', '', $fileName);
            $filePathNoExtName = $filePath . $fileNoExt;

            if (!str_ends_with($fileName, ".src")) continue;

            $testCase = new Test();
            $testCase->pathToTest = $filePathNoExtName;
            $testCase->testFileName = $fileNoExt;

            $this->addMissingFiles($testCase);

            if (file_exists($testCase->pathToTest . ".rc")) {
                $testCase->expectedCode = intval(trim(file_get_contents($testCase->pathToTest.".rc")));
            }
            if (file_exists($testCase->pathToTest . ".in")) {
                if (file_get_contents($testCase->pathToTest . ".in") != "") {
                    $testCase->userInputFile = true;
                }
            }
            $this->testCases[] = $testCase;


            if ($this->args->debug) {
                printf( "*** Adding new testcase ***\nPath to test:   %s\nTest file name: %s\n\n",
                    $testCase->pathToTest, $testCase->testFileName);
            }
        }
    }

    public function testParser() {
        if ($this->args->testParser) {
            foreach ($this->testCases as $testCase) {

                if ($this->args->debug) {
                    printf( "*** Launching parser test ***\nPath to test:   %s\nTest file name: %s\n\n",
                        $testCase->pathToTest, $testCase->testFileName);
                }

                $inputFileSuffix = ".src";
                $testCase->stdoutFilePar = $testCase->pathToTest.".stdout_par.tmp";
                $testCase->stderrFilePar = $testCase->pathToTest.".stderr_par.tmp";

                $dataRedirection = " > " . $testCase->stdoutFilePar . " 2> " . $testCase->stderrFilePar;

                $command = PHP_ALIAS." ".$this->args->parseScriptPath." < ".$testCase->pathToTest.$inputFileSuffix.$dataRedirection;

                // $interpretOutput = stdout, $interpretCode = exit code of interpret
                $output = NULL;
                exec($command, $output, $parserCode);
                $testCase->parserCode = $parserCode;

                if (!file_exists($testCase->pathToTest.".stdout_par.tmp")) {
                    continue;
                }
                // use jexamxml only when --int-only is set, otherwise, there is nothing to compare to
                if ($testCase->parserCode == 0 && $this->args->parseOnly) {
                    $command = JAVA_JAR_ALIAS. " ".$this->args->jexamPath." ".$testCase->pathToTest.".out ".$testCase->pathToTest.".stdout_par.tmp";
                    exec($command, $output, $jexamCode);
                    $testCase->jexamCode = $jexamCode;
                }
            }
        }
    }

    public function testInterpret() {
        if ($this->args->testInterpret) {
            foreach ($this->testCases as $testCase) {

                // do not test if not supposed to or previous parser test failed already
                if ($this->args->parseOnly || $testCase->parserCode != 0 || $testCase->jexamCode != 0) continue;

                if ($this->args->debug) {
                    printf( "*** Launching interpret test ***\nPath to test:   %s\nTest file name: %s\n\n",
                        $testCase->pathToTest, $testCase->testFileName);
                }

                // with flag --int-only, input file for interpret is in .src
                if ($this->args->interpretOnly) {
                    $inputFileSuffix = ".src";
                } else {
                    $inputFileSuffix = ".stdout_par.tmp";
                }

                $testCase->stdoutFileInt = $testCase->pathToTest.".stdout_int.tmp";
                $testCase->stderrFileInt = $testCase->pathToTest.".stderr_int.tmp";
                $dataRedirection = " > " . $testCase->stdoutFileInt . " 2> " . $testCase->stderrFileInt;
                $inputData = "";
                if ($testCase->userInputFile) {
                    $inputData = " --input=".$testCase->pathToTest.".in ";
                }

                $command = PYTHON_ALIAS." ".$this->args->interpretScriptPath." --source=".
                    $testCase->pathToTest.$inputFileSuffix.$dataRedirection. $inputData;

                // $interpretOutput = stdout, $interpretCode = exit code of interpret
                $output = NULL;
                exec($command, $output, $interpretCode);
                $testCase->interpretCode = $interpretCode;

                if (file_get_contents($testCase->pathToTest.".stdout_int.tmp") ==
                    file_get_contents($testCase->pathToTest.".out")) {
                    $testCase->intOutputMatch = true;
                } else {
                    $testCase->intOutputMatch = false;
                }
            }
        }
    }


    // add missing .in, .out, .rc if absent
    public function addMissingFiles(Test $testCase) {
            // if .rc file does not exist, create one with resultCode = 0
            if (!file_exists($testCase->pathToTest . ".rc")) {
                file_put_contents($testCase->pathToTest . ".rc", "0");
            }
            // if .in file does not exist, create an empty one
            if (!file_exists($testCase->pathToTest . ".in")) {
                file_put_contents($testCase->pathToTest . ".in", "");
            }
            // if .out file does not exist, create an empty one
            if (!file_exists($testCase->pathToTest . ".out")) {
                file_put_contents($testCase->pathToTest . ".out", "");
            }
    }
}

class htmlPrinter
{
    public array $testCases;
    public string $templateBegin = "<html lang=\"en\">
                                        <head>
                                            <title>
                                                IPP Test Report
                                            </title>
                                            <style>
                                                div {
                                                    margin: auto;
                                                }
                                                table {
                                                    display: table;
                                                    border-collapse: separate;
                                                    box-sizing: border-box;
                                                    text-indent: initial;
                                                    border-spacing: 2px;
                                                    border-color: grey;
                                                }
                                                th {
                                                    display: table-cell;                                                    
                                                    vertical-align: inherit;
                                                    font-weight: bold;
                                                    text-align: -internal-center;
                                                }
                                                tr {
                                                    display: table-row;
                                                    vertical-align: inherit;
                                                    border-color: inherit;
                                                }
                                                #results td, #results th {
                                                    border: 1px solid #ddd;
                                                    padding: 5px;
                                                }
                                                #results td {
                                                    text-align: center; 
                                                }
                                                #results {
                                                    font-family: Arial, Helvetica, sans-serif;
                                                    border-collapse: collapse;
                                                    width: 100%;
                                                }
                                                #table-header {
                                                    background-color: #04AA6D;
                                                }
                                                
                                                
                                                #test-row:{background-color: #ddd;}
                                                #test-row:nth-child(even){background-color: #f2f2f2;}
                                                #test-row:hover {background-color: #ddd;}
                                                
                                                #summary td, #summary th {
                                                    border: 1px solid #ddd;
                                                    padding: 8px;
                                                }
                                                #summary th {
                                                    padding: 15px;
                                                }
                                                #summary td {
                                                    text-align: center; 
                                                }
                                                #summary {
                                                    font-family: Arial, Helvetica, sans-serif;
                                                    border-collapse: collapse;
                                                    margin: 0 auto;
                                                    margin-bottom: 20px;
                                                }
                                                #test-header {
                                                    background-color: #04AA6D;
                                                }
                                                
                                                #summary-row tr{background-color: #f2f2f2;}
                                                #summary-row:{background-color: #ddd;}
                                            </style>
                                        </head>
                                        <body>
                                        <div>
                                        <h1 class=\"test-results-header\">
                                            Test Report
                                        </h1>";
    public string $tableBegin = "<table id=\"results\">
                                    <tr id=\"table-header\">
                                        <th>
                                            No.
                                        </th>
                                        <th>
                                            Test File Name
                                        </th>
                                        <th>
                                            Path to test file
                                        </th>
                                        <th>
                                            Expected result code
                                        </th>
                                        <th>
                                            Final RC
                                        </th>
                                        <th>
                                            Final result
                                        </th>
                                        <th>
                                            RC parser
                                        </th>
                                        <th>
                                            JEXAM match
                                        </th>
                                        <th>
                                            RC interpet
                                        </th>
                                        <th>
                                            Interpret output match
                                        </th>
                                        <th>
                                            Error message(interpet or parser)
                                        </th>
                                    </tr>";
    public string $templateEnd = "</div></table></body></html>";
    public string $tests = "";
    public string $testsSummary = "";
    public int $totalTestCount = 0;
    public int $successTestCount = 0;
    public InputArguments $args;

    public function __construct($testCases, $args) {
        $this->testCases = $testCases;
        $this->args = $args;
        $this->addTests();
        $this->generateSummary();
        $this->generateHtmlFile();

    }

    public function getMessageFromFile($path): string {
        $resultString = "";
        if (file_exists($path)) {
            $resultString = trim(file_get_contents($path));
        }
        return $resultString;
    }

    public function addTests() {
        $counter = 1;
        foreach ($this->testCases as $testCase) {
            $this->totalTestCount++;
            $result_parser = "";
            $result_int = "";
            $result_int_out = "";
            $result_final = "";
            $result_code = 0;
            $result_jexam = "";
            $stderr_msg = "";

            if ($this->args->parseOnly) {
                if ($testCase->parserCode == $testCase->expectedCode) {
                    $result_parser = $testCase->parserCode;
                    $result_code = $testCase->parserCode;
                    if ($testCase->jexamCode == 0) {
                        $result_jexam = "OK";
                        $result_final = "OK";
                    } else {
                        $result_jexam = "FAIL";
                    }
                } else {
                    $stderr_msg = $this->getMessageFromFile($testCase->stderrFilePar);
                }

            }

            if ($this->args->interpretOnly) {
                if ($testCase->interpretCode == $testCase->expectedCode) {
                    $result_int = $testCase->interpretCode;
                    $result_code = $testCase->interpretCode;
                    if ($testCase->intOutputMatch) {
                        $result_int_out = "OK";
                        $result_final = "OK";
                    } else {
                        $result_int_out = "FAIL";
                    }
                } else {
                    $stderr_msg = $this->getMessageFromFile($testCase->stderrFileInt);
                }
            }

            if ($this->args->testInterpret && $this->args->testParser) {
                $result_parser = $testCase->parserCode;
                if ($testCase->parserCode == 0) {
                    if ($testCase->jexamCode == 0) {
                        $result_jexam = "OK";
                        $result_int = $testCase->interpretCode;
                        if ($testCase->interpretCode == 0) {
                            if ($testCase->intOutputMatch) {
                                $result_final = "OK";
                                $result_int_out = "OK";
                            } else {
                                $result_int_out = "FAIL";
                                $result_code = -1;
                                $stderr_msg = "Interpret output does not match";
                            }
                        } else {
                            $result_code = $testCase->interpretCode;
                            if ($testCase->interpretCode == $testCase->expectedCode) {
                                $result_final = "OK";
                            } else {
                                $result_final = "FAIL";
                                $stderr_msg = $this->getMessageFromFile($testCase->stderrFileInt);
                            }
                        }
                    } else {
                        $result_jexam = "FAIL";
                        $result_final = "FAIL";
                        $result_code = -1;
                        $stderr_msg = "Parser output does not match";
                    }
                } else {
                    $result_code = $testCase->parserCode;
                    if ($testCase->parserCode == $testCase->expectedCode) {
                        $result_final = "OK";
                    } else {
                        $result_final = "FAIL";
                        $stderr_msg = $this->getMessageFromFile($testCase->stderrFilePar);
                    }
                }

            }

            if ($result_final == "OK") {
                $this->successTestCount++;
                $result_styling = " style=\"background-color:#04AA6D;\"";
            } else {
                $result_styling = " style=\"background-color:#ff6961;\"";
            }

            $this->tests .= "<tr id=\"test-row\">
                                <td>
                                    " . $counter . "
                                </td>
                                <td>
                                    " . $testCase->testFileName . "
                                </td>
                                <td>
                                    " . $testCase->pathToTest . "
                                </td>
                                <td>
                                    " . $testCase->expectedCode . "
                                </td>
                                <td>
                                    " . $result_code . "
                                </td>
                                <td".$result_styling.">
                                    " . $result_final . "
                                </td>
                                <td>
                                    " . $result_parser . "
                                </td>
                                <td>
                                    " . $result_jexam . "
                                </td>
                                <td>
                                    " . $result_int . "
                                </td>
                                <td>
                                    " . $result_int_out . "
                                </td>
                                <td>
                                    " . $stderr_msg . "
                                </td>
                            </tr>";
            $counter++;
        }
    }

    public function generateSummary() {
        $this->testsSummary .= "<table id=\"summary\">
                                    <tr id=\"table-header\">
                                        <th>
                                            Total number of tests
                                        </th>
                                        <th>
                                            Successful tests
                                        </th>
                                        <th>
                                            Failed tests
                                        </th>
                                        <th>
                                            Percentage successful
                                        </th>
                                    </tr>
                                    <tr>
                                        <td>
                                            ".$this->totalTestCount."
                                        </td>
                                        <td>
                                            ".$this->successTestCount."
                                        </td>
                                        <td>
                                            ".$this->totalTestCount-$this->successTestCount."
                                        </td>
                                        <td>
                                            <b>
                                            ".intval(($this->successTestCount/$this->totalTestCount)*100) ."%
                                            </b> 
                                        </td>
                                    </tr>
                                </table>";
    }

    public function generateHtmlFile() {
        $htmlContent = $this->templateBegin.$this->testsSummary.$this->tableBegin.$this->tests.$this->templateEnd;
        $outputHtmlFile = fopen("testResult.html", "w");
        fwrite($outputHtmlFile, $htmlContent);
        fclose($outputHtmlFile);
    }

    public function readOutputFile(string $filename): string
    {
        $output = "";
        if (file_exists($filename)) {
            $contents = file_get_contents($filename);
            $lines = explode("\n", $contents);

            foreach ($lines as $line) {
                $output .= $line . '<br>';
            }
        }
        return $output;
    }
}

class Test {
    public string   $testFileName           = "";
    public string   $pathToTest             = "";
    public int      $parserCode             = 0;
    public int      $jexamCode              = 0;
    public int      $expectedCode           = 0;
    public int      $interpretCode          = 0;
    public string   $stderrFilePar          = "";
    public string   $stdoutFilePar          = "";
    public string   $stderrFileInt          = "";
    public string   $stdoutFileInt          = "";
    public bool     $intOutputMatch         = false;
    public bool     $userInputFile          = false;
}

function handleError(int $errno) {
    switch ($errno) {
        case ERROR_DIR_DIRECTORY:
            fputs(STDERR, "Directory given in parameter --directory must end with '\'.\n");
            exit(ERROR_DIRECTORY);
        case ERROR_TEST_PATH:
            fputs(STDERR, "Invalid directory of testing files given in parameter --directory.\n");
            exit(ERROR_DIRECTORY);
        case ERROR_JEXAMPATH:
            fputs(STDERR, "Invalid directory given in parameter --jexampath.\n");
            exit(ERROR_DIRECTORY);
        case ERROR_PARSE_PATH:
            fputs(STDERR, "Invalid directory given in parameter --jexampath.\n");
            exit(ERROR_DIRECTORY);
        case ERROR_COLLIDING_PARAMS:
            fputs(STDERR, "Invalid or colliding input arguments.\n");
            exit(ERROR_COLLIDING_PARAMS);
    }
}

$tester = new Tester();


