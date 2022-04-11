<?php
ini_set('display_errors','STDERR');

/* PREDEFINED ERROR CODES */
// missing parameter, invalid parameter combination
const ERROR_PARAM = 10;
// error opening input files(non-existent, insufficient privileges)
const ERROR_FILE_IN = 11;
// error opening output files(insufficient privileges, write error)
const ERROR_FILE_OUT = 12;
// internal error
const ERROR_INT = 99;
// missing or invalid header in input file
const ERROR_HEADER = 21;
// unknown or incorrect opcode in input file
const ERROR_CODE = 22;
// lexical or syntactical error in source file
const ERROR_PARSE = 23;

/* IMPLEMENTATION DEPENDENT ERROR CODES */
// empty input file
const ERROR_EMPTY = -3;
// invalid opcode argument label
const ERROR_LABEL = 30;
// invalid opcode argument var
const ERROR_VAR = 31;
// invalid opcode argument type
const ERROR_TYPE = 32;
// invalid opcode argument symbol
const ERROR_SYMBOL = 32;
// invalid opcode argument constant
const ERROR_CONSTANT = 33;

// strip line from comment
function lineStripComment(string $line): string {
    $result = strpos($line, '#');
    if($result != false){
        return substr($line, 0, $result);
    }
    return $line;
}

// clear line from comment, trims
function lineClearAndSplit(string $line): array {
    $line = trim(lineStripComment(trim($line, " \n")), " ");
    return preg_split('/\s+/', trim(lineStripComment(trim($line, " \n")), " "));
}

// check if line is empty or a comment(begins with '#')
function lineIsEmpty(string $line): bool {
    if ($line == "\n" || $line=="" || str_starts_with($line, "#")) {
        return true;
    }
    return false;
}

// compares supposed number of arguments with $line data
function checkArgumentCount(array $args, int $nOfParams):bool {
    $n = count($args) - 1;
    if ($n == -1 || $n != $nOfParams) {
        handleError(ERROR_PARSE);
    }
    return true;
}

// check opcode type
function instructionParseType(string $arg, int $argOrder):bool {
    $result = preg_match("/^(int|string|bool)$/", $arg);
    if (!$result) {
        handleError(ERROR_TYPE);
    }
    xmlWriteArg("type", $arg, $argOrder);
    return true;
}

// check opcode symbol
function instructionParseSymb(string $arg, int $argOrder):bool {
    // symbol can be variable or constant
    if (instructionParseConst($arg, $argOrder, true)) {
        return true;
    } else if (instructionParseVar($arg, $argOrder, true)) {
        return true;
    } else {
        handleError(ERROR_SYMBOL);
    }
    // obligatory dead code
    return false;
}

// check constant, if param $symbol is set, do not exit(function was called from instructionParseSymb())
function instructionParseConst(string $arg, int $argOrder,bool $symbol = false):bool {
    // split argument into parts
    // using explode, it needs to be separated at the first occurrence of '@', other '@' can be in string value
    $parts = explode("@", $arg, 2);
    // check type
    $result = preg_match("/^(int|bool|string|nil)$/", $parts[0]);
    if (!$result) {
        if($symbol) {
            return false;
        } else {
            handleError(ERROR_CONSTANT);
        }
    }

    switch ($parts[0]) {
        case "int":
            if (preg_match("/^[+-]?[\d]+$/", $parts[1])) {
                $result = 1;
            } else if (preg_match("/^0x[0-9a-fA-F]*$/", $parts[1])) {
                $result = 1;
            } else if (preg_match("/^0[1-7][0-7]*$/", $parts[1])) {
                $result = 1;
            } else {
                $result = 0;
            }
            break;
        case "bool":
            $result = preg_match("/^(true|false)$/", $parts[1]);
            break;
        case "nil":
            $result = $parts[1] == "nil";
            break;
        case "string":
            $exp = explode("\\", $parts[1]);
            if (strpos($exp[0], "#")) {
                handleError(ERROR_CONSTANT);
            }
            unset($exp[0]);
            if (count($exp) > 0) {
                foreach ($exp as $e) {
                    if (!preg_match("/^\d\d\d$/", substr($e, 0, 3))) {
                        handleError(ERROR_CONSTANT);
                    }
                    if (strpos($e, "#")) {
                        handleError(ERROR_CONSTANT);
                    }
                }
                $result = true;
            } else {
                $result = preg_match("/^[^#]*$/", $parts[1]);
            }
    }

    if (!$result) {
        handleError(ERROR_CONSTANT);
    }

    xmlWriteArg($parts[0], $parts[1], $argOrder);
    return true;
}

// check variable, if param $symbol is set, do not exit(function was called from instructionParseSymb())
function instructionParseVar(string $arg, int $argOrder, bool $symbol = false):bool {
    // split argument into parts
    $parts = preg_split("/@/", $arg);
    // check frame
    $result = preg_match("/^(GF)|(LF)|(TF)$/", $parts[0]);
    if (!$result) {
        if ($symbol) {
            return false;
        } else {
            handleError(ERROR_VAR);
        }
    }
    // check variable name
    $result = preg_match("/^[a-zA-Z_$&%*!?-][a-zA-Z0-9_$&%*!?-]*$/", $parts[1]);
    if (!$result) {
        // no need to return false in case of $symbol, it would stop variable check after failed frame name check
        handleError(ERROR_VAR);
    }
    xmlWriteArg("var", $arg, $argOrder);
    return true;
}

// check instruction label
function instructionParseLabel(string $arg, int $argOrder):bool {
    $result = preg_match("/^[a-zA-Z_$&%*!?-][a-zA-Z0-9_$&%*!?-]*$/", $arg);
    if (!$result) {
        handleError(ERROR_LABEL);
    }
    xmlWriteArg("label", $arg, $argOrder);
    return true;
}

function xmlInit() {
    global $domDocument;
    $domDocument = new DOMDocument("1.0", "UTF-8");
    $domDocument->formatOutput = true;
}

// write root and IPPcode element to output document
function xmlWriteBeginning() {
    global $domDocument, $rootElement;

    $rootElement = $domDocument->createElement("program");
    $rootElement = $domDocument->appendChild($rootElement);

    $lang_attr = $domDocument->createAttribute("language");
    $lang_attr->value = "IPPcode22";
    $rootElement->appendChild($lang_attr);

}

// write argument no.1 to xml output document
function xmlWriteArg(string $argType, string $argValue, int $argOrder) {
    global $domDocument, $inst_elem;
    $arg_elem = $domDocument->createElement("arg".$argOrder);
    $inst_elem->appendChild($arg_elem);

    $type_attr = $domDocument->createAttribute("type");
    $type_attr->value = $argType;

    $arg_elem->appendChild($type_attr);

    $arg_text = $domDocument->createTextNode($argValue);
    $arg_elem->appendChild($arg_text);
}

// write instruction to xml output document
function xmlWriteCommand(string $opcode, int $order) {
    global $domDocument, $rootElement, $inst_elem;

    // instruction
    $inst_elem = $domDocument->createElement("instruction");
    $rootElement->appendChild($inst_elem);

    $instruction_attr_order = $domDocument->createAttribute("order");
    $instruction_attr_order->value = $order;

    $inst_elem->appendChild($instruction_attr_order);

    $instruction_attr_opcode = $domDocument->createAttribute("opcode");
    $instruction_attr_opcode->value = $opcode;
    $inst_elem->appendChild($instruction_attr_opcode);
}

function processInstruction(array $line_arr, int $order) {
    $opcode = strtoupper($line_arr[0]);

    switch($opcode) {

        /* <var><symb> instructions */
        case "MOVE":
        case "INT2CHAR":
        case "TYPE":
        case "STRLEN":
        case "NOT":
            checkArgumentCount($line_arr, 2);
            xmlWriteCommand($opcode, $order);
            instructionParseVar($line_arr[1], 1);
            instructionParseSymb($line_arr[2], 2);
            break;

        /* <var><type> instructions */
        case "READ":
            checkArgumentCount($line_arr, 2);
            xmlWriteCommand($opcode, $order);
            instructionParseVar($line_arr[1], 1);
            instructionParseType($line_arr[2], 2);
            break;


        /* <label><symb1><symb2> instructions */
        case "JUMPIFEQ":
        case "JUMPIFNEQ":
            checkArgumentCount($line_arr, 3);
            xmlWriteCommand($opcode, $order);
            instructionParseLabel($line_arr[1], 1);
            instructionParseSymb($line_arr[2], 2);
            instructionParseSymb($line_arr[3], 3);
            break;

        /* <var><symb1><symb2> instructions */
        case "ADD":
        case "SUB":
        case "MUL":
        case "IDIV":
        case "LT":
        case "GT":
        case "EQ":
        case "AND":
        case "OR":
        case "STRI2INT":
        case "CONCAT":
        case "GETCHAR":
        case "SETCHAR":
            checkArgumentCount($line_arr, 3);
            xmlWriteCommand($opcode, $order);
            instructionParseVar($line_arr[1], 1);
            instructionParseSymb($line_arr[2], 2);
            instructionParseSymb($line_arr[3], 3);

            break;

        /* <symb> instructions */
        case "PUSHS":
        case "EXIT":
        case "DPRINT":
        case "WRITE":
            checkArgumentCount($line_arr, 1);
            xmlWriteCommand($opcode, $order);
            instructionParseSymb($line_arr[1], 1);
            break;

        /* <var> instructions */
        case "POPS":
        case "DEFVAR":
            checkArgumentCount($line_arr, 1);
            xmlWriteCommand($opcode, $order);
            instructionParseVar($line_arr[1], 1);
            break;

        /* 0 arg instructions */
        case "BREAK":
        case "RETURN":
        case "CREATEFRAME":
        case "PUSHFRAME":
        case "POPFRAME":
            checkArgumentCount($line_arr, 0);
            xmlWriteCommand($opcode, $order);
            break;

        /* <label> arg instructions */
        case "LABEL":
        case "CALL":
        case "JUMP":
            checkArgumentCount($line_arr, 1);
            xmlWriteCommand($opcode, $order);
            instructionParseLabel($line_arr[1], 1);
            break;
        default:
            handleError(ERROR_CODE);
    }
}

// check for header correctness
function processHeader() {
    // file input for debugging
    global $myFile;
    do {
        $line = trim(lineStripComment(trim(fgets(STDIN), " \n")));
    } while(lineIsEmpty($line));

    // check for regex match, die if not found
    if(!preg_match("/^\.IPPcode22$/", $line)) {
        handleError(ERROR_HEADER);
    }
}

function parseLines() {
    // file input for debugging
    global $myFile;
    // parse first line
    processHeader();
    // initialising line index for output xml order attribute
    $instCounter = 1;
    // parsing all lines
    while($line = fgets(STDIN)) {
        // skip if line is empty or comment
        if (lineIsEmpty($line)) continue;
        // trims line from whitespaces, newlines, split by whitespace
        $line = lineClearAndSplit($line);
        // parse for command and it's arguments
        processInstruction($line, $instCounter);
        $instCounter++;
    }
}

// parses input arguments, only acceptable is --help
function parseInputArguments(): int {

    $argOptions = array("help");
    $givenParams = getopt("", $argOptions);

    global $argc;
    if(array_key_exists("help", $givenParams)) {
        if ($argc == 2) {
            fputs(STDOUT, "IPPcode22 parser \nUsage: parse.php < file_to_parse.src\n--help to display this message.\n");
            exit(0);
        }
        handleError(ERROR_PARAM);
    }
    return 0;
}

// handles error, prints error message and returns the errno back
function handleError(int $errno) {
    switch ($errno) {
        case ERROR_PARAM:
            fputs(STDERR, "Invalid number of input arguments. Use argument --help for use \n");
            exit(ERROR_PARAM);
        case ERROR_EMPTY:
            fputs(STDERR, "Empty file, nothing to read.\n");
            exit(ERROR_EMPTY);
        case ERROR_HEADER:
            fputs(STDERR, "Invalid header line.\n");
            exit(ERROR_HEADER);
        case ERROR_PARSE:
            fputs(STDERR, "Incorrect command parameters.\n");
            exit(ERROR_PARSE);
        case ERROR_LABEL:
            fputs(STDERR, "Incorrect opcode argument 'label'.\n");
            exit(ERROR_PARSE);
        case ERROR_VAR:
            fputs(STDERR, "Incorrect opcode argument 'var'.\n");
            exit(ERROR_PARSE);
        case ERROR_TYPE:
            fputs(STDERR, "Incorrect opcode argument 'type'.\n");
            exit(ERROR_PARSE);
        case ERROR_SYMBOL:
            fputs(STDERR, "Incorrect opcode argument 'symbol'.\n");
            exit(ERROR_PARSE);
        case ERROR_CONSTANT:
            fputs(STDERR, "Incorrect opcode argument 'constant'.\n");
            exit(ERROR_PARSE);
        case ERROR_CODE:
            fputs(STDERR, "Incorrect opcode.\n");
            exit(ERROR_CODE);
    }
}

// file input for debugging
//$myFile = fopen("/Users/pavel/VUT-IPP-2/test/both/write/write_undef3b.src", "r");

parseInputArguments();

// global variable of output document
$domDocument = null;
$rootElement = null;
$inst_elem = null;
xmlInit();
xmlWriteBeginning();

parseLines();

// null check
if ($domDocument) {
    echo $domDocument->saveXML();
}