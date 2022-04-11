import xml.etree.ElementTree as eT
import sys
import argparse

err_nums = {
    -1: "Failure exit.",
    -2: "Stack error.",
    -3: "No input arguments. Nothing to interpret :)",
    -4: "Invalid input params.",
    -5: "Invalid source file.",
    -6: "Recurring order number.",
    -7: "Recurring label name.",
    -8: "Recurring variable in global frame.",
    -9: "Variable in was not found in frame.",
    -10: "Incompatible variable MOVE types.",
    -11: "Invalid variable name.",
    -12: "Defvar, but no var was given.",
    -13: "Jump to non-existent label name.",
    -14: "Invalid input type and value.",
    31: "Invalid XML format.",
    32: "Unexpected XML structure.",
    52: "Undefined label, or redefinition.",
    53: "Invalid operands.",
    55: "Empty local frame stack before POPFRAME command.",
    56: "Empty call stack before RETURN command.",
    57: "Runtime error. Division by zero or invalid return value of EXIT."
}


def exit_error(err_number):
    print(err_nums[err_number], file=sys.stderr)
    sys.exit(err_number)


class Stack:
    def __init__(self):
        self.stack = []
        self.stack_len = 0

    def push_value(self, value_type: str, value):
        self.stack.append([value_type, value])
        self.stack_len += 1

    def pop_value(self):
        if self.stack_len != 0:
            return self.stack.pop()
        else:
            exit_error(-2)

    def is_present(self, value_type: str, value):
        if [value_type, value] in self.stack:
            return True
        return False

    def is_empty(self):
        return not self.stack_len

    def top(self):
        return self.stack[-1]


class Instruction:
    def __init__(self, inst_opcode, order):
        self.inst_opcode = inst_opcode
        self.order = order
        self.args = []


class Argument:
    def __init__(self, kind, value=None, name=None, arg_order=None):
        # var | int | string | bool | label
        self.kind = kind
        self.value = value
        self.name = name
        self.frame = None
        self.arg_order = arg_order
        self.assign_frame()

    def assign_frame(self):
        if self.name is not None and self.kind == 'var':
            self.frame = self.name[:2]

    def get_pure_name(self):
        if self.name is not None and self.kind == 'var':
            return self.name[3:]


class Variable:
    def __init__(self, name=None, type_v=None, initialized=False, value=None):
        self.type_v = type_v
        self.pure_name = None
        self.name = name
        self.initialized = initialized
        self.value = value

        self.assign_pure_name()

    def assign_pure_name(self):
        if self.name is not None:
            self.pure_name = self.name[3:]


class Interpreter:

    label_list = []
    order_numbers = []

    def __init__(self):
        self.instructions = []

        self.source_file = None
        self.source_is_file = False
        self.input_file = None
        self.input_is_file = False

        # {label_name: index_of_instruction, ...}
        self.labels = {}

        # stack of values
        self.data_stack = Stack()

        # stack of TODO
        self.call_stack = Stack()
        self.frame_stack = Stack()

        self.local_frame = Stack()
        # self.local_frame_valid = False

        self.global_frame = []

        self.temp_frame = []
        self.temp_frame_valid = False

        self.inst_num = 0
        self.xml_root = None

        self.parse_input_arguments()

        # reads user input or file and constructs tree
        self.parse_source()

        # checks if root has correct tag and name
        self.check_root()

        # parses instruction and creates instruction list with arguments
        self.parse_element_tree()

        # sorts instruction list by order number of instruction
        self.sort_instructions()

        self.find_labels()

        self.execute_code()

    def sort_instructions(self):
        self.instructions.sort(key=lambda inst: inst.order)

    def find_labels(self):
        counter = 0
        for inst in self.instructions:
            if inst.inst_opcode == 'LABEL':
                label = inst.args[0]
                if label.value in self.labels:
                    exit_error(52)
                self.labels[label.value] = counter
            counter += 1

    def check_root(self):
        # check obligatory tag and attribute
        if self.xml_root.tag != 'program' or self.xml_root.attrib['language'] != 'IPPcode22':
            exit_error(32)

    def parse_source(self):
        if self.source_is_file:
            with open(self.source_file, 'r') as f:
                lines = f.readlines()
        else:
            lines = []
            while (line := input()) != '':
                lines.append(line)
        try:
            self.xml_root = eT.ElementTree(eT.fromstringlist(lines)).getroot()
        except eT.ParseError:
            exit_error(31)

    def parse_element_tree(self):
        for element in self.xml_root:
            if element.tag != 'instruction' or 'order' not in element.attrib or 'opcode' not in element.attrib:
                exit_error(32)

            if not element.attrib['order'].isdigit():
                exit_error(32)

            # checking if there is a recurrence of order numbers
            if not self.check_int(element.attrib['order']):
                exit_error(32)
            if int(element.attrib['order']) in self.order_numbers or int(element.attrib['order']) < 1:
                exit_error(32)
            self.order_numbers.append(int(element.attrib['order']))
            instruction = Instruction(element.attrib['opcode'], int(element.attrib['order']))

            for argument in element.iter():
                if argument != element:
                    self.parse_argument(instruction, argument)
            instruction.args.sort(key=lambda x: x.arg_order)

            counter = 1
            for arg in instruction.args:
                if arg.arg_order != counter:
                    exit_error(32)
                counter += 1

            self.instructions.append(instruction)

    def parse_argument(self, inst: Instruction, arg: eT.Element):
        if arg.tag not in ('arg1', 'arg2', 'arg3'):
            exit_error(32)

        arg_order = int(arg.tag[3])

        if arg.attrib['type'] == 'var':
            # type var      <arg1 type="var">GF@var</arg1>
            # GF@x | LF@x | TF@x
            if len(arg.text) < 4 or arg.text[:3] not in ('GF@', 'LF@', 'TF@'):
                # TODO: errno?
                exit_error(31)
            inst.args.append(Argument(kind='var', name=arg.text, arg_order=arg_order))

        elif arg.attrib['type'] == 'string':
            # type string   <arg1 type="string">hello world</arg1>
            # if not arg.text:
            #     # TODO: errno?
            #     exit_error(31)
            for index, subst in enumerate(arg.text.split("\\")):
                if index == 0:
                    arg.text = subst
                else:
                    if int(subst[0:3]) < 0 or int(subst[0:3]) > 999:
                        exit_error(69)
                    arg.text = arg.text + chr(int(subst[0:3])) + subst[3:]

            inst.args.append(Argument(kind='string', value=arg.text, arg_order=arg_order))

        elif arg.attrib['type'] == 'int':
            # type int      <arg1 type="int">123</arg1>
            # check if empty and valid integer
            if not arg.text or not self.check_int(arg.text):
                exit_error(32)
            inst.args.append(Argument(kind='int', value=int(arg.text), arg_order=arg_order))

        elif arg.attrib['type'] == 'bool':
            # type bool     <arg1 type="bool">true</arg1>
            if arg.text not in ('false', 'true'):
                # TODO: errno?
                exit_error(31)
            inst.args.append(Argument(kind='bool', value=arg.text, arg_order=arg_order))

        elif arg.attrib['type'] == 'nil':
            # type label    <arg1 type="nil">nil</arg1>
            if arg.text != "nil":
                # TODO: errno?
                exit_error(31)
            inst.args.append(Argument(kind='nil', value='nil', arg_order=arg_order))

        elif arg.attrib['type'] == 'label':
            # type label    <arg1 type="label">label_name</arg1>
            # empty label name
            if not arg.text:
                # TODO: errno?
                exit_error(31)
            inst.args.append(Argument(kind='label', value=arg.text, arg_order=arg_order))

        elif arg.attrib['type'] == 'type':
            # type type    <arg1 type="type">type_name</arg1>
            if arg.text not in ('int', 'string', 'bool'):
                # TODO: errno?
                exit_error(31)
            inst.args.append(Argument(kind=arg.text, arg_order=arg_order))

        # unknown argument type
        else:
            # TODO: err number
            exit_error(32)

    def get_frame(self, arg: Argument):
        if arg.frame == 'GF':
            return self.global_frame
        if arg.frame == 'TF':
            if not self.temp_frame_valid:
                exit_error(55)
            return self.temp_frame
        if arg.frame == 'LF':
            if self.local_frame.is_empty():
                exit_error(55)
                return
            return self.local_frame.top()[1]

    def get_value_and_type_of_symbol(self, arg: Argument):
        if arg.kind == 'var':
            var = self.get_var(arg)
            return var.value, var.type_v

        elif arg.kind in ('string', 'int', 'bool', 'nil'):
            return arg.value, arg.kind

    def get_var(self, arg):
        frame = self.get_frame(arg)
        for var in frame:
            if var.pure_name == arg.get_pure_name():
                return var
        # TODO: errno? variable not found
        exit_error(-69)

    @staticmethod
    def print_help():
        print("""   Usage:
            interpret.py [--help] [--input=<input_file>] [--source=<source_file>]
            some more helpful message
            """)

    @staticmethod
    def check_int(string: str):
        if string[0] in ('-', '+'):
            return string[1:].isdigit()
        return string.isdigit()

    def parse_input_arguments(self):
        parser = argparse.ArgumentParser(description='Helping you', add_help=False)
        parser.add_argument('--help', dest='help', action='store_true', default=False,
                            help='show this message')
        parser.add_argument('--input', type=str, dest='input_file', default=False, required=False)
        parser.add_argument('--source', type=str, dest='source_file', default=False, required=False)
        arguments = vars(parser.parse_args())

        # argument checks
        if arguments['help'] and not arguments['input_file'] and not arguments['source_file']:
            self.print_help()
            sys.exit(0)
        elif arguments['help'] and (arguments['input_file'] or arguments['source_file']):
            exit_error(-4)
        elif not (arguments['input_file'] or arguments['source_file']):
            exit_error(-3)

        if arguments['input_file']:
            self.input_is_file = True
            self.input_file = arguments['input_file']

        if arguments['source_file']:
            self.source_is_file = True
            self.source_file = arguments['source_file']

    def execute_code(self):
        while True:
            # check if we already executed the last instruction
            if self.inst_num > len(self.instructions) - 1:
                break

            curr_inst: Instruction = self.instructions[self.inst_num]
            opcode = curr_inst.inst_opcode.upper()

            if opcode == 'LABEL':
                self.inst_num += 1
            elif opcode == 'DEFVAR':
                arg = curr_inst.args[0]
                frame = self.get_frame(arg)
                frame.append(Variable(name=arg.name))
                self.inst_num += 1
            elif opcode == 'MOVE':
                var: Variable = self.get_var(curr_inst.args[0])
                symbol = curr_inst.args[1]
                value, type_v = self.get_value_and_type_of_symbol(symbol)
                var.type_v, var.value, var.initialized = type_v, value, True
                self.inst_num += 1
            elif opcode == 'CALL':
                label = curr_inst.args[0]
                self.call_stack.push_value('int', self.inst_num + 1)
                self.inst_num = self.labels[label.value]
            elif opcode == 'RETURN':
                if self.call_stack.is_empty():
                    exit_error(56)
                    return
                self.inst_num = self.call_stack.pop_value()[1]
            elif opcode == 'CREATEFRAME':
                self.temp_frame = []
                self.temp_frame_valid = True
                self.inst_num += 1
            elif opcode == 'PUSHFRAME':
                if not self.temp_frame_valid:
                    exit_error(55)
                self.local_frame.push_value('frame', self.temp_frame)
                self.temp_frame_valid = False
                self.inst_num += 1
            elif opcode == 'POPFRAME':
                if self.local_frame.is_empty():
                    exit_error(55)
                    return
                self.temp_frame = self.local_frame.pop_value()[1]
                self.temp_frame_valid = True
                self.inst_num += 1
            elif opcode == 'PUSHS':
                value, type_v = self.get_value_and_type_of_symbol(curr_inst.args[0])
                self.data_stack.push_value(type_v, value)
                self.inst_num += 1
            elif opcode == 'POPS':
                var: Variable = self.get_var(curr_inst.args[0])
                var.type_v, var.value = self.data_stack.pop_value()
                var.initialized = True
                self.inst_num += 1
            elif opcode in ('ADD', 'SUB', 'MUL', 'IDIV'):
                var: Variable = self.get_var(curr_inst.args[0])
                value1, type_v1 = self.get_value_and_type_of_symbol(curr_inst.args[1])
                value2, type_v2 = self.get_value_and_type_of_symbol(curr_inst.args[2])
                if type_v1 != 'int' or type_v2 != 'int':
                    exit_error(53)
                    return
                var.type_v = 'int'
                var.initialized = True
                if opcode == 'ADD':
                    var.value = value1 + value2
                elif opcode == 'SUB':
                    var.value = value1 - value2
                elif opcode == 'MUL':
                    var.value = value1 * value2
                elif opcode == 'IDIV':
                    if not value2:
                        exit_error(57)
                    var.value = value1 // value2
                self.inst_num += 1
            elif opcode in ('LG', 'GT', 'EQ'):
                var = self.get_var(curr_inst.args[0])
                value1, type_v1 = self.get_value_and_type_of_symbol(curr_inst.args[1])
                value2, type_v2 = self.get_value_and_type_of_symbol(curr_inst.args[2])

                if type_v1 != type_v2 or type_v1 not in ('int', 'string', 'bool', 'nil'):
                    # TODO: errno?
                    exit_error(69)
                    return
                var.type_v = bool
                if opcode == 'LG':
                    if type_v1 == 'nil':
                        # TODO: errno?
                        exit_error(69)
                    var.value = 'true' if value1 < value2 else 'false'
                elif opcode == 'GT':
                    if type_v1 == 'nil':
                        # TODO: errno?
                        exit_error(69)
                    var.value = 'true' if value1 > value2 else 'false'
                elif opcode == 'EQ':
                    var.value = 'true' if value1 == value2 else 'false'
                self.inst_num += 1
            elif opcode == 'READ':
                var: Variable = self.get_var(curr_inst.args[0])
                value1, type_v1 = self.get_value_and_type_of_symbol(curr_inst.args[1])
                if self.input_file:
                    with open(self.input_file, 'r') as f:
                        line = f.readline().strip(' \n')
                else:
                    line = input()
                if type_v1 == 'int':
                    try:
                        var.type_v, var.value, var.initialized = 'int', int(line), True
                    except ValueError:
                        var.type_v, var.value, var.initialized = 'nil', 'nil', True
                elif type_v1 == 'bool':
                    var.type_v, var.value, var.initialized = 'bool', 'true' if line == 'true' else 'false', True
                elif type_v1 == 'string':
                    var.type_v, var.value, var.initialized = 'string', line, True

                self.inst_num += 1
            elif opcode == 'WRITE':
                value, type_v = self.get_value_and_type_of_symbol(curr_inst.args[0])
                if type_v == 'nil':
                    print('', end='')
                else:
                    print(value, end='')
                self.inst_num += 1
            elif opcode == 'AND':
                var: Variable = self.get_var(curr_inst.args[0])
                value1, type_v1 = self.get_value_and_type_of_symbol(curr_inst.args[1])
                value2, type_v2 = self.get_value_and_type_of_symbol(curr_inst.args[2])

                if type_v1 != type_v2 or type_v1 != 'bool':
                    # TODO: errno?
                    exit_error(69)
                var.type_v = 'bool'
                var.initialized = True
                var.value = 'true' if value1 == 'true' and value2 == 'true' else 'false'
                self.inst_num += 1
            elif opcode == 'OR':
                var: Variable = self.get_var(curr_inst.args[0])
                value1, type_v1 = self.get_value_and_type_of_symbol(curr_inst.args[1])
                value2, type_v2 = self.get_value_and_type_of_symbol(curr_inst.args[2])

                if type_v1 != 'bool' or type_v2 != 'bool':
                    # TODO: errno?
                    exit_error(69)
                var.type_v = 'bool'
                var.initialized = True
                var.value = 'true' if value1 == 'true' or value2 == 'true' else 'false'
                self.inst_num += 1
            elif opcode == 'NOT':
                var: Variable = self.get_var(curr_inst.args[0])
                value, type_v = self.get_value_and_type_of_symbol(curr_inst.args[1])

                if type_v != 'bool':
                    # TODO: errno?
                    exit_error(69)
                var.type_v = 'bool'
                var.initialized = True
                var.value = 'true' if value == 'false' else 'false'
                self.inst_num += 1
            elif opcode == 'INT2CHAR':
                var: Variable = self.get_var(curr_inst.args[0])
                value, type_v = self.get_value_and_type_of_symbol(curr_inst.args[1])
                if type_v != 'int':
                    # TODO: errno?
                    exit_error(69)
                try:
                    char = chr(value)
                except ValueError:
                    exit_error(58)
                    return
                var.type_v, var.value, var.initialized = 'string', char, True
                self.inst_num += 1
            elif opcode == 'STRI2INT':
                var: Variable = self.get_var(curr_inst.args[0])
                value1, type_v1 = self.get_value_and_type_of_symbol(curr_inst.args[1])
                value2, type_v2 = self.get_value_and_type_of_symbol(curr_inst.args[2])
                if type_v1 != 'string' or type_v2 != 'int':
                    # TODO: errno?
                    exit_error(69)
                if value2 > len(value1) - 1:
                    exit_error(58)
                try:
                    new_value = ord(value1[value2])
                except ValueError:
                    # TODO: errno?
                    exit_error(69)
                    return
                var.type_v, var.value, var.initialized = 'int', new_value, True
                self.inst_num += 1
            elif opcode == 'CONCAT':
                var: Variable = self.get_var(curr_inst.args[0])
                value1, type_v1 = self.get_value_and_type_of_symbol(curr_inst.args[1])
                value2, type_v2 = self.get_value_and_type_of_symbol(curr_inst.args[2])
                if type_v1 != 'string' or type_v2 != 'string':
                    # TODO: errno?
                    exit_error(69)
                    return
                var.type_v, var.value, var.initialized = 'string', value1 + value2, True
                self.inst_num += 1
            elif opcode == 'STRLEN':
                var: Variable = self.get_var(curr_inst.args[0])
                value, type_v = self.get_value_and_type_of_symbol(curr_inst.args[1])
                if type_v != 'string':
                    # TODO: errno?
                    exit_error(69)
                    return
                var.type_v, var.value, var.initialized = 'int', len(value), True
                self.inst_num += 1
            elif opcode == 'GETCHAR':
                var: Variable = self.get_var(curr_inst.args[0])
                value1, type_v1 = self.get_value_and_type_of_symbol(curr_inst.args[1])
                value2, type_v2 = self.get_value_and_type_of_symbol(curr_inst.args[2])
                if type_v1 != 'string' or type_v2 != 'int':
                    # TODO: errno?
                    exit_error(69)
                    return
                if value2 > len(value1) - 1:
                    exit_error(58)
                var.type_v, var.value, var.initialized = 'string', value1[value2], True
                self.inst_num += 1
            elif opcode == 'SETCHAR':
                var: Variable = self.get_var(curr_inst.args[0])
                value1, type_v1 = self.get_value_and_type_of_symbol(curr_inst.args[1])
                value2, type_v2 = self.get_value_and_type_of_symbol(curr_inst.args[2])
                if var.type_v != 'string' or type_v1 != 'int' or type_v2 != 'string':
                    # TODO: errno?
                    exit_error(69)
                    return
                if value1 > len(var.value) - 1 or not value2:
                    exit_error(58)
                new_var_val = list(var.value)
                new_var_val[value1] = value2[0]
                new_var_val = ''.join(new_var_val)
                var.type_v, var.value, var.initialized = 'string', new_var_val, True
                self.inst_num += 1
            elif opcode == 'DPRINT':
                value1, type_v1 = self.get_value_and_type_of_symbol(curr_inst.args[0])
                if type_v1 == 'nil':
                    print('', end='', file=sys.stderr)
                else:
                    print(value1, end='', file=sys.stderr)
                self.inst_num += 1
            elif opcode == 'BREAK':
                print('Printing some useful information about my current state.')
                self.inst_num += 1
            elif opcode == 'JUMP':
                label = curr_inst.args[0]
                if label.value not in self.labels:
                    exit_error(52)
                    return
                self.inst_num = self.labels[label.value]
            elif opcode in ('JUMPIFEQ', 'JUMPIFNEQ'):
                label = curr_inst.args[0]
                value1, type_v1 = self.get_value_and_type_of_symbol(curr_inst.args[1])
                value2, type_v2 = self.get_value_and_type_of_symbol(curr_inst.args[2])
                if not self.labels[label.value]:
                    exit_error(69)
                    return

                if type_v1 == 'nil' or type_v2 == 'nil':
                    self.inst_num = self.labels[label.value]
                if type_v1 != type_v2:
                    exit_error(53)
                if opcode == 'JUMPIFEQ':
                    if value1 == value2:
                        self.inst_num = self.labels[label.value]
                    else:
                        self.inst_num += 1
                elif opcode == 'JUMPIFNEQ':
                    if value1 != value2:
                        self.inst_num = self.labels[label.value]
                    else:
                        self.inst_num += 1
            else:
                # unknown instruction
                exit_error(32)


interpret = Interpreter()
