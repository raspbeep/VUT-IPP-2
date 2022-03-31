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
    32: "",
    53: "Invalid comparison operands.",
    55: "Empty local frame stack before POPFRAME command.",
    56: "Empty call stack before RETURN command.",
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


class Instruction:
    def __init__(self, inst_opcode, order):
        self.inst_opcode = inst_opcode
        self.order = order
        self.args = []


class Argument:
    def __init__(self, kind, value=None, name=None):
        # var | int | string | bool | label
        self.kind = kind
        self.value = value
        self.name = name
        self.frame = None
        self.assign_frame()

    def assign_frame(self):
        if self.name is not None:
            self.frame = self.name[3:]


class Variable:
    def __init__(self, name=None, type_v=None, initialized=False, value=None):
        self.type_v = type_v
        self.name = name
        self.initialized = initialized
        self.value = value


class Interpreter:
    group_var_symbol = ('MOVE', 'INT2CHAR', 'TYPE', 'STRLEN', 'NOT')
    group_var_type = ['READ']
    group_var_symbol_symbol = ('ADD', 'SUB', 'MUL', 'IDIV', 'LT', 'GT', 'EQ', 'AND', 'OR', 'STR2INT', 'CONCAT','GETCHAR', 'SETCHAR')
    group_symbol = ('PUSHS', 'EXIT', 'DPRINT', 'WRITE')
    group_var = ('POPS', 'DEFVAR')
    group_no_args = ('BREAK', 'RETURN', 'CREATEFRAME', 'PUSHFRAME', 'POPFRAME')
    group_label = ('LABEL', 'CALL', 'JUMP')

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
        self.local_frame = []
        self.global_frame = []
        self.temp_frame = []

        self.inst_num = 0
        self.xml_root = None

        # reads user input or file and constructs tree
        self.parse_source()

        # checks if root has correct tag and name
        self.check_root()

        # parses instruction and creates instruction list with arguments
        self.parse_element_tree()

        self.find_labels()

    def find_labels(self):
        for index, inst in self.instructions:
            if inst.inst_opcode == 'LABEL':
                label = inst.args[0]
                self.labels[label.name] = index

    def check_root(self):
        # check obligatory tag and attribute
        if self.xml_root.tag != 'program' or self.xml_root.attrib['language'] != 'IPPcode22':
            exit_error(31)

    def parse_source(self):
        if self.source_is_file:
            with open(self.source_file, 'r') as f:
                lines = f.readlines()
        else:
            lines = []
            while (line := input()) != '':
                lines.append(line)
        try:
            self.xml_root = eT.ElementTree(eT.fromstringlist(lines))
        except eT.ParseError:
            # TODO: errno?
            exit_error(-5)

    def parse_element_tree(self):
        for element in self.xml_root:
            if "order" not in element.attrib or "opcode" not in element.attrib:
                # TODO: errno?
                exit_error(31)

            # checking if there is a recurrence of order numbers
            if int(element.attrib['order']) in self.order_numbers:
                # TODO: errno?
                exit_error(-6)

            self.order_numbers.append(int(element.attrib['order']))

            instruction = Instruction(element.attrib['opcode'], int(element.attrib['order']))

            for argument in element.iter():
                self.parse_argument(instruction, argument)
            self.instructions.append(instruction)

    def parse_argument(self, inst: Instruction, arg: eT.Element):
        if arg.tag not in ('arg1', 'arg2', 'arg3'):
            # TODO: errno?
            exit_error(31)

        if arg.attrib['type'] == 'var':
            # type var      <arg1 type="var">GF@var</arg1>
            # GF@x | LF@x | TF@x
            if len(arg.text) < 4 or arg.text[:3] not in ('GF@', 'LF@', 'TF@'):
                # TODO: errno?
                exit_error(31)
            inst.args.append(Argument(kind='var', name=arg.text[3:]))

        elif arg.attrib['type'] == 'string':
            # type string   <arg1 type="string">hello world</arg1>
            if not arg.text:
                # TODO: errno?
                exit_error(31)
            inst.args.append(Argument(kind='string', value=arg.text))

        elif arg.attrib['type'] == 'int':
            # type int      <arg1 type="int">123</arg1>
            # check if empty and valid integer
            if not arg.text or not self.check_int(arg.text):
                # TODO: errno?
                exit_error(31)
            inst.args.append(Argument(kind='int', value=int(arg.text)))

        elif arg.attrib['type'] == 'bool':
            # type bool     <arg1 type="bool">true</arg1>
            if arg.text not in ('false', 'true'):
                # TODO: errno?
                exit_error(31)
            inst.args.append(Argument(kind='bool', value=arg.text))

        elif arg.attrib['type'] == 'nil':
            # type label    <arg1 type="label">label_name</arg1>
            if len(arg.text) != "nil":
                # TODO: errno?
                exit_error(31)
            inst.args.append(Argument(kind='nil', value='nil'))

        elif arg.attrib['type'] == 'label':
            # type label    <arg1 type="label">label_name</arg1>
            # empty label name
            if not arg.text:
                # TODO: errno?
                exit_error(31)
            inst.args.append(Argument(kind='label', value=arg.text))

        # unknown argument type
        else:
            # TODO: err number
            exit_error(32)

    def get_frame(self, arg: Argument):
        if arg.frame == 'LF':
            return self.local_frame
        if arg.frame == 'GF':
            return self.global_frame
        if arg.frame == 'TF':
            return self.temp_frame

    def get_value_and_type_of_symbol(self, arg: Argument):
        if arg.kind == 'var':
            var = self.get_var(arg)
            return var.value, var.type_v

        elif arg.kind in ('string', 'int', 'bool', 'nil'):
            return arg.value, arg.kind

    def get_var(self, arg):
        frame = self.get_frame(arg)
        for var in frame:
            if var.name == arg.name:
                return var
        # TODO: errno?
        exit_error(-69)

    @staticmethod
    def print_help():
        print("""   Usage:
            main.py [--help] [--input <input_file>] [--source <source_file>]
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

            opcode = curr_inst.inst_opcode

            if opcode == 'LABEL':
                self.inst_num += 1
            elif opcode == 'DEFVAR':
                arg = curr_inst.args[0]
                frame = self.get_frame(arg)
                frame.append(Variable(name=arg.name))

            elif opcode == 'MOVE':
                var: Variable = self.get_var(curr_inst.args[0])
                symbol = curr_inst.args[1]
                value, type_v = self.get_value_and_type_of_symbol(symbol)
                var.type_v, var.value, var.initialized = type_v, value, True
                self.inst_num += 1
            elif opcode == 'CALL':

            elif opcode == 'RETURN':
                if frames_and_stacks['CF'].is_empty():
                    exit_error(56)
                jump_to = frames_and_stacks['CF'].pop_value()
                current_inst = jump_to[1]
            elif opcode == 'CREATEFRAME':
                frames_and_stacks['TF'] = []
                current_inst += 1
            elif opcode == 'PUSHFRAME':
                frames_and_stacks['FS'].push_value('TF', frames_and_stacks['TF'])
                frames_and_stacks['LF'] = frames_and_stacks['TF']
                # undefined temporary frame
                frames_and_stacks['TF'] = []
                current_inst += 1
            elif inst[1] == 'POPFRAME':
                if frames_and_stacks['LF'].is_empty():
                    exit_error(55)
                frames_and_stacks['TF'] = frames_and_stacks['LF'].pop_value()[1]
                current_inst += 1
            elif opcode == 'PUSHS':
                frames_and_stacks = push_symbol_to_data_stack(frames_and_stacks, inst[2][0])
                current_inst += 1
            elif opcode == 'POPS':
                frames_and_stacks = pop_var_from_data_stack(frames_and_stacks, inst[2][0])
                current_inst += 1
            elif opcode == 'ADD':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])
                if symbol1[0] != 'int' or symbol2[0] != 'int':
                    exit_error(56)
                set_value_of_var(frames_and_stacks, inst[2][0], symbol1[1] + symbol2[1])
                current_inst += 1
            elif inst[1] == 'SUB':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])
                if symbol1[0] != 'int' or symbol2[0] != 'int':
                    exit_error(56)
                set_value_of_var(frames_and_stacks, inst[2][0], symbol1[1] - symbol2[1])
                current_inst += 1

            elif inst[1] == 'READ':
                if args['input']:
                    with open(args['input'], 'r') as f:
                        line = f.readline().strip('\n ')
                else:
                    line = input().strip('\n ')

                if not check_input_type(inst[2][1], line):
                    exit_error(-14)
                if inst[2][1] == 'int':
                    line = int(line)
                set_value_of_var(frames_and_stacks, inst[2][0], int(line))
            elif inst[1] == 'WRITE':
                symbol = get_value_from_symbol(frames_and_stacks, inst[2][0])
                if symbol[0] == 'nil' and symbol[1] == 'nil':
                    print('', end='')
                else:
                    print(symbol[1], end='')
            elif inst[1] == 'MUL':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])
                if symbol1[0] != 'int' or symbol2[0] != 'int':
                    exit_error(56)
                set_value_of_var(frames_and_stacks, inst[2][0], symbol1[1] * symbol2[1])
                current_inst += 1
            elif inst[1] == 'IDIV':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])
                if symbol1[0] != 'int' or symbol2[0] != 'int' or symbol2[1] == 0:
                    exit_error(56)
                set_value_of_var(frames_and_stacks, inst[2][0], symbol1[1] // symbol2[1])
                current_inst += 1
            elif inst[1] == 'LG':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])
                if (symbol1[0] == 'int' and symbol2[0] == 'int') or (symbol1[0] == 'string' and symbol2[0] == 'string'):
                    set_value_of_var(frames_and_stacks, inst[2][0], symbol1[1] > symbol2[1])
                else:
                    exit_error(53)
                current_inst += 1
            elif inst[1] == 'GT':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])
                if (symbol1[0] == 'int' and symbol2[0] == 'int') or (symbol1[0] == 'string' and symbol2[0] == 'string'):
                    set_value_of_var(frames_and_stacks, inst[2][0], symbol1[1] < symbol2[1])
                else:
                    exit_error(53)
                current_inst += 1
            elif inst[1] == 'EQ':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])
                if (symbol1[0] == 'int' and symbol2[0] == 'int') or (symbol1[0] == 'string' and symbol2[0] == 'string'):
                    set_value_of_var(frames_and_stacks, inst[2][0], symbol1[1] == symbol2[1])
                elif symbol1[0] == 'nil' or symbol2[0] == 'nil':
                    set_value_of_var(frames_and_stacks, inst[2][0], symbol1[1] == symbol2[1])
                else:
                    exit_error(53)
                current_inst += 1
            elif inst[1] == 'AND':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])
                if symbol1[0] == 'bool' and symbol2[0] == 'bool':
                    if symbol1[0] == 'true' and symbol2[0] == 'true':
                        set_value_of_var(frames_and_stacks, inst[2][0], 'true')
                    else:
                        set_value_of_var(frames_and_stacks, inst[2][0], 'false')
                else:
                    exit_error(53)
                current_inst += 1
            elif inst[1] == 'OR':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])
                if symbol1[0] == 'bool' and symbol2[0] == 'bool':
                    if symbol1[0] == 'true' or symbol2[0] == 'true':
                        set_value_of_var(frames_and_stacks, inst[2][0], 'true')
                    else:
                        set_value_of_var(frames_and_stacks, inst[2][0], 'false')
                else:
                    exit_error(53)
            elif inst[1] == 'NOT':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                if symbol1[0] == 'bool':
                    if symbol1[0] == 'true':
                        set_value_of_var(frames_and_stacks, inst[2][0], 'false')
                    else:
                        set_value_of_var(frames_and_stacks, inst[2][0], 'true')
                else:
                    exit_error(53)
            elif inst[1] == 'INT2CHAR':
                symbol = get_value_from_symbol(frames_and_stacks, inst[2][1])
                if symbol[0] != 'int':
                    # TODO: errno?
                    exit_error(58)
                    return
                try:
                    char = chr(symbol[1])
                except ValueError:
                    exit_error(58)
                    return
                set_value_of_var(frames_and_stacks, inst[1], char)
                current_inst += 1
            elif inst[1] == 'STRI2INT':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])
                if symbol1[0] != 'str' or symbol2[0] != 'int':
                    # TODO: errno?
                    exit_error(58)
                # -1 due to indexation
                if len(symbol1[1]) - 1 < symbol2[1]:
                    exit_error(58)
                try:
                    char_to_convert = ord(symbol1[1][symbol2[1]])
                except ValueError:
                    exit_error(58)
                    return
                var_name = inst[2][0][1][3:]
                set_value_of_var(frames_and_stacks, var_name, char_to_convert)
                current_inst += 1
            elif inst[1] == 'CONCAT':
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])
                if symbol1[0] != 'str' or symbol2[0] != 'str':
                    exit_error(58)
                    return
                concat_string = symbol1[1] + symbol2[1]
                set_value_of_var(frames_and_stacks, inst[2][0], concat_string)
                current_inst += 1
            elif inst[1] == 'STRLEN':
                symbol = get_value_from_symbol(frames_and_stacks, inst[2][1])
                if symbol[0] != 'str':
                    exit_error(58)
                    return
                variable = get_var_from_frame(frames_and_stacks[get_frame_abbr(inst[2][0])], inst[2][0][1][3:])
                variable.type_v = 'int'
                variable.value = len(symbol[1])
                variable.is_initialized = True
                set_value_of_var(frames_and_stacks, inst[2][0], len(symbol[1]))
                current_inst += 1
            elif inst[1] == 'GETCHAR':
                variable = get_var_from_frame(frames_and_stacks[get_frame_abbr(inst[2][0])], inst[2][0][1][3:])
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])

                if symbol1[0] != 'str' or symbol2[0] != 'int':
                    exit_error(58)
                    return

                if symbol2[1] > len(symbol1[1]) - 1:
                    exit_error(58)
                    return
                variable.type_v = 'str'
                variable.initialized = True
                variable.value = symbol1[1][symbol2[1]]
            elif inst[1] == 'SETCHAR':
                variable = get_var_from_frame(frames_and_stacks[get_frame_abbr(inst[2][0])], inst[2][0][1][3:])
                symbol1 = get_value_from_symbol(frames_and_stacks, inst[2][1])
                symbol2 = get_value_from_symbol(frames_and_stacks, inst[2][2])

                if symbol1[0] != 'str' or symbol2[0] != 'int':
                    exit_error(58)
                    return

                if symbol2[1] > len(symbol1[1]) - 1:
                    exit_error(58)
                    return
                variable.type_v = 'str'
                variable.initialized = True
                variable.value = symbol1[1][symbol2[1]]



def check_input_type(type_v: str, value) -> bool:
    if type_v == 'int':
        if not check_int(value):
            exit_error(56)

    elif type_v == 'bool':
        if value not in ['true', 'false']:
            exit_error(56)
    return True



if __name__ == '__main__':
    xml_tree: eT.ElementTree = parse_source()

    execute()
