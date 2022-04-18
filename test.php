<?php
////
// Copyright 2022
//
// @file test.php
// @author xkrato61 - Pavel Kratochvil
//
// @brief Tests parse.php and interpret.py scripts
//
////

//// Error codes ////
// directory error
const ERROR_DIRECTORY = 41;
// directory error parsing parameter --directory
const ERROR_DIR_DIRECTORY = 41;
// jexampath path give in param --jexampath does not exist
const ERROR_JEXAMPATH = -4;
// parse script path give in param --parse-script does not exist
const ERROR_PARSE_PATH = -5;
// testing files' directory given in param --directory does not exist
const ERROR_TEST_PATH = -6;
// invalid or colliding input arguments
const ERROR_PARAMS = 10;

const PHP_ALIAS = "php8.1";
const PYTHON_ALIAS = "python3.8";
const JAVA_JAR_ALIAS = "java -jar";

// A singleton class for parsing and validating user input arguments
// used for storing and future retrieval during testing
class InputArguments {
    public string $testDirectory = "./";
    public string $parseScriptPath = "./parse.php";
    public string $interpretScriptPath = "./interpret.py";
    public string $jexamPath = "/pub/courses/ipp/jexamxml/jexamxml.jar";
    public bool $recursion = false;
    public bool $parseOnly = false;
    public bool $interpretOnly = false;
    public bool $testInterpret = true;
    public bool $testParser = true;
    public bool $cleanFiles = true;
    public bool $debug = false;

    // construct function representing the sole purpose of instance of this class
    public function __construct() {
        global $argc, $argv;
        $possibleBeginsWith = array("--directory=", "--parse-script=", "--int-script=", "--jexampath=");
        $possibleOptions = array("--help", "--recursive", "--parse-only", "--int-only", "--no-clean", "--debug");
        $argOptions = array("help", "directory:", "recursive", "parse-script:", "int-script:", "parse-only", "int-only", "jexampath:", "no-clean", "debug");
        $givenParams = getopt("", $argOptions);

        // assign attribute values according to given arguments
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
                handleError(ERROR_PARAMS);
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
                handleError(ERROR_PARAMS);
            }
            $this->testParser = false;
            $this->interpretOnly = true;
        }

        if (array_key_exists("parse-only", $givenParams)) {
            if (array_key_exists("int-only", $givenParams) || array_key_exists("int-script", $givenParams)) {
                handleError(ERROR_PARAMS);
            }
            $this->testInterpret = false;
            $this->parseOnly = true;
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

        // check for unknown or missing parameters
        for ($i = 1; $i < $argc; $i++) {
            $passed = false;
            // input argument must either completely match one of $possibleOptions
            if (in_array($argv[$i], $possibleOptions)) {
                $passed = true;
            }
            // or must begin with one of $possibleBeginsWith, for instance "--int-script="
            foreach ($possibleBeginsWith as $p) {
                if (str_starts_with($argv[$i], $p)) {
                    // if it completely matches, no further required information apart from the flag was given
                    if ($argv[$i] == $p) handleError(ERROR_PARAMS);
                    $passed = true;
                    break;
                }
            }
            if (!$passed) handleError(ERROR_PARAMS);
        }
    }
}

// A singleton class for finding tests, parser testing, interpret testing
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

    // cleans up all intermediate files generated during testing
    // input files that were missing (.in, .out, .rc) are left
    public function tempFilesCleanUp() {
        if ($this->args->cleanFiles) {
            foreach ($this->testCases as $testCase) {
                if (file_exists($testCase->pathToTest . ".stdout_par.tmp")) {
                    unlink($testCase->pathToTest . ".stdout_par.tmp");
                }
                if (file_exists($testCase->pathToTest . ".stderr_par.tmp")) {
                    unlink($testCase->pathToTest . ".stderr_par.tmp");
                }
                if (file_exists($testCase->pathToTest . ".stdout_int.tmp")) {
                    unlink($testCase->pathToTest . ".stdout_int.tmp");
                }
                if (file_exists($testCase->pathToTest . ".stderr_int.tmp")) {
                    unlink($testCase->pathToTest . ".stderr_int.tmp");
                }
            }
        }
    }

    // finds all tests in default or given directory
    public function findTests() {
        if ($this->args->recursion) {
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
        } else {
            $files = scandir($this->args->testDirectory);
            foreach($files as $file) {
                // skip current and parent directory to avoid looping
                if ($file == "." || $file == "..") continue;

                $filePath = $this->args->testDirectory;

                $fileNoExt = preg_replace('/\\.[^.\\s]{2,4}$/', '', $file);
                $filePathNoExtName = $filePath . $fileNoExt;
                if (is_dir($filePathNoExtName)) continue;

                if (!str_ends_with($file, ".src")) continue;

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

    }

    // launches parser tests on all found testcases
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
                    if (!file_exists($this->args->jexamPath)) {
                        handleError(ERROR_JEXAMPATH);
                    }
                    $command = JAVA_JAR_ALIAS. " ".$this->args->jexamPath." ".$testCase->pathToTest.".out ".$testCase->pathToTest.".stdout_par.tmp";
                    exec($command, $output, $jexamCode);
                    $testCase->jexamCode = $jexamCode;
                }
            }
        }
    }

    // launches interpret tests on all found testcases
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


                // compare interpret outputs using diff
                $command = "diff ".$testCase->pathToTest.".stdout_int.tmp ".$testCase->pathToTest.".out";
                exec($command, $output, $diffCode);
                if ($diffCode == 0) {
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

// A singleton class for generating output summary in the form of HTML page
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

    // construct function representing a lifecycle of htmlPrinter class instance
    public function __construct($testCases, $args) {
        $this->testCases = $testCases;
        $this->args = $args;
        $this->addTests();
        $this->generateSummary();
        $this->generateHtmlFile();
    }

    // get message from generated intermediate file either stdout or stderr generated by parse.php or interpret.py
    public function getMessageFromFile($path): string {
        $resultString = "";
        if (file_exists($path)) {
            $resultString = trim(file_get_contents($path));
        }
        return $resultString;
    }

    // generates one line in resulting table of tests in html page
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

    // generates user-friendly summary of all tests
    public function generateSummary() {
        $percentageSuccessful = 0;
        if ($this->totalTestCount != 0) {
            $percentageSuccessful = intval(($this->successTestCount/$this->totalTestCount)*100);
        }
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
                                            ". $percentageSuccessful ."%
                                            </b> 
                                        </td>
                                    </tr>
                                </table>";
    }

    // concatenates template of beginning, ending and results of all tests into one html file
    // outputs the file to either stdout or if --debug flag is set to a separate .html file
    public function generateHtmlFile() {
        $htmlContent = $this->templateBegin.$this->testsSummary.$this->tableBegin.$this->tests.$this->templateEnd;

        // output to external .html file for testing purposes
        if ($this->args->debug) {
            $outputHtmlFile = fopen("testResult.html", "w");
            fwrite($outputHtmlFile, $htmlContent);
            fclose($outputHtmlFile);
        } else {
            printf("%s", $htmlContent);
        }
    }
}

// A class used for storing a single test
// stores all necessary information during testing
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

// outputs message to stderr and exits with code given in param errno
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
        case ERROR_PARAMS:
            fputs(STDERR, "Unknown, invalid or colliding input arguments.\n");
            exit(ERROR_PARAMS);
    }
}

$tester = new Tester();


