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


def print_help():
    print("""   Usage:
        main.py [--help] [--input <input_file>] [--source <source_file>]
        some more helpful message
        """)


def parse_arguments() -> dict:
    parser = argparse.ArgumentParser(description='Helping you', add_help=False)
    parser.add_argument('--help', dest='help', action='store_true', default=False,
                        help='show this message')
    parser.add_argument('--input', type=str, dest='input_file', default=False, required=False)
    parser.add_argument('--source', type=str, dest='source_file', default=False, required=False)
    arguments = vars(parser.parse_args())
    if arguments['help'] and not arguments['input_file'] and not arguments['source_file']:
        print_help()
        sys.exit(0)
    elif arguments['help'] and (arguments['input_file'] or arguments['source_file']):
        exit_error(-4)
    elif not (arguments['input_file'] or arguments['source_file']):
        exit_error(-3)

    return arguments


def parse_source() -> eT.ElementTree:
    if args['source_file']:
        with open(args['source_file'], 'r') as f:
            lines = f.readlines()
    else:
        lines = []
        while (line := input()) != '':
            lines.append(line)
    try:
        return eT.ElementTree(eT.fromstringlist(lines))
    except eT.ParseError:
        exit_error(-5)


def parse_argument(child: eT.Element) -> list:
    if not (child.tag == 'arg1' or child.tag == 'arg2' or child.tag == 'arg3'):
        exit_error(31)

    if child.attrib['type'] == 'var':
        # type var      <arg1 type="var">GF@var</arg1>
        # GF@x | LF@x | TF@x
        if len(child.text) < 4:
            exit_error(31)
        if not (child.text[:3] == 'GF@' or child.text[:3] == 'LF@' or child.text[:3] == 'TF@'):
            exit_error(31)
        return [int(child.tag[-1]), child.attrib['type'], child.text]

    elif child.attrib['type'] == 'string':
        # type string   <arg1 type="string">hello world</arg1>
        if len(child.text) < 1:
            exit_error(31)
        return [int(child.tag[-1]), child.attrib['type'], child.text]

    elif child.attrib['type'] == 'int':
        # type int      <arg1 type="int">123</arg1>
        if len(child.text) < 1 or not check_int(child.text):
            exit_error(31)
        return [int(child.tag[-1]), child.attrib['type'], int(child.text)]

    elif child.attrib['type'] == 'bool':
        # type bool     <arg1 type="bool">true</arg1>
        if not (child.text == 'false' or child.text == 'true'):
            exit_error(31)
        return [int(child.tag[-1]), child.attrib['type'], child.text]

    elif child.attrib['type'] == 'nil':
        # type label    <arg1 type="label">label_name</arg1>
        if len(child.text) != "nil":
            exit_error(31)
        return [int(child.tag[-1]), child.attrib['type'], child.text]

    else:
        # TODO: err number
        exit_error(32)


def parse_element_tree() -> list:
    order_nums = []
    label_names = []
    inst_list = []
    root = xml_tree.getroot()

    # check obligatory tag and attribute
    if root.tag != 'program' or root.attrib['language'] != 'IPPcode22':
        exit_error(31)

    for element in root:
        if "order" not in element.attrib or "opcode" not in element.attrib:
            exit_error(31)
        # checking if there is a recurrence of order numbers
        if int(element.attrib['order']) in order_nums:
            exit_error(-6)
        order_nums.append(int(element.attrib['order']))
        if element.attrib['opcode'] == 'LABEL':
            if element.attrib['opcode'] in label_names:
                exit_error(-7)
            label_names.append(element.attrib['opcode'])

        children_list = []
        [children_list.append(parse_argument(child)) for child in element.iter() if child is not element]
        children_list.sort(key=lambda x: x[0])
        inst_list.append([int(element.attrib['order']), element.attrib['opcode'], children_list])
    return inst_list


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


class Variable:
    def __init__(self, name: str, type_v: str, value: int):
        self.type_v = type_v
        self.name = name
        self.value = value


def check_int(string: str):
    if string[0] in ('-', '+'):
        return string[1:].isdigit()
    return string.isdigit()


def get_var_from_frame(frame: list, name: str) -> bool | Variable:
    for variable in frame:
        if variable.name == name:
            return variable
    return False


def is_var_in_frame(frame: list, name: str):
    for variable in frame:
        if variable.name == name:
            return True
    return False


def push_symbol_to_data_stack(f_and_s: dict, arg: list):
    # push variable
    frame = get_frame_abbr(arg[2])

    if arg[1] == 'var':
        name = arg[2]
        if not is_var_in_frame(f_and_s[frame], name):
            exit_error(-11)
            return

        variable = get_var_from_frame(f_and_s[frame], name)
        f_and_s['DS'].push_value([variable.type_v, variable.value])
    # push constant
    else:
        # [symbol_type, symbol_value]
        f_and_s['DS'].push_value([arg[1], arg[2]])
    return f_and_s


def get_frame_abbr(name: str) -> str:
    return name[:2]


def pop_var_from_data_stack(f_and_s: dict, arg: list):
    name = arg[2]
    f_and_s['DS'].pop_value()
    frame = f_and_s[get_frame_abbr(arg[2])]

    if f_and_s['DS'].is_empty():
        exit_error(56)
        return
    if not is_var_in_frame(frame, name):
        exit_error(56)
        return

    variable = get_var_from_frame(frame, name)
    # TODO: add this? variable.type_v = f_and_s['DS'].pop_value()[0]
    variable.value = f_and_s['DS'].pop_value()[1]
    return f_and_s


def get_value_from_symbol(f_and_s: dict, arg: list):
    if arg[1] == 'var':
        frame_abbr = get_frame_abbr(arg[2])
        if not is_var_in_frame(f_and_s[frame_abbr], arg[2][3:]):
            exit_error(56)
            return
        variable = get_var_from_frame(f_and_s[frame_abbr], arg[2])
        return [variable.type_v, variable.value]
    return [arg[1], arg[2]]


def set_value_of_var(f_and_s: dict, arg: list, new_value):
    name = arg[2]
    type_v = arg[1]
    frame_abbr = get_frame_abbr(name)
    if not is_var_in_frame(f_and_s[frame_abbr], name[3:]):
        exit_error(56)
        return
    variable = get_var_from_frame(f_and_s[frame_abbr], name[3:])
    variable.value = new_value
    variable.type_v = type_v


def check_input_type(type_v: str, value: int | str) -> bool:
    if type_v == 'int':
        if not check_int(value):
            exit_error(56)

    elif type_v == 'bool':
        if value not in ['true', 'false']:
            exit_error(56)
    return True


def execute():
    frames_and_stacks = {
        'GF': [],
        'LF': Stack(),
        'TF': [],
        # frame stack
        'FS': Stack(),
        # call stack
        'CF': Stack(),
        # [data_type, data_value]
        'DS': Stack(),
    }
    # dictionary storing label_name: number_of_instruction
    label_dict = {}

    current_inst = 0

    while True:
        if current_inst > len(instruction_list) - 1:
            break
        inst = instruction_list[current_inst]
        if inst[1] == 'LABEL':
            label_dict[inst[2]] = current_inst
            current_inst += 1
        elif inst[1] == 'DEFVAR':
            if inst[2][0][1] == 'var':
                arg_frame = frames_and_stacks[inst[2][0][2][:2]]
                var_name = inst[2][0][2][3:]
                if not is_var_in_frame(arg_frame, var_name):
                    arg_frame.append(Variable(var_name, '', 0))
                    current_inst += 1
                else:
                    exit_error(-11)
            else:
                exit_error(-12)
        elif inst[1] == 'MOVE':
            if inst[2][0][1] == 'var':
                arg_frame = frames_and_stacks[inst[2][0][2][:2]]
                var_name = inst[2][0][2][3:]
                variable = get_var_from_frame(arg_frame, var_name)
                if not variable:
                    exit_error(-9)
                variable.init = True
                variable.type_v = inst[2][1][1]
                variable.value = inst[2][1][2]
                current_inst += 1
        elif inst[1] == 'CALL':
            label_name = inst[2][0][2]
            # storing current position in stack [name_of_current_instruction, incremented_inst_number]
            frames_and_stacks['CF'].push_value(instruction_list[current_inst], [current_inst + 1])
            if label_name not in label_dict:
                exit_error(-13)
            current_inst = label_dict[label_name]
        elif inst[1] == 'RETURN':
            if frames_and_stacks['CF'].is_empty():
                exit_error(56)
            jump_to = frames_and_stacks['CF'].pop_value()
            current_inst = jump_to[1]
        elif inst[1] == 'CREATEFRAME':
            frames_and_stacks['TF'] = []
            current_inst += 1
        elif inst[1] == 'PUSHFRAME':
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
        elif inst[1] == 'PUSHS':
            frames_and_stacks = push_symbol_to_data_stack(frames_and_stacks, inst[2][0])
            current_inst += 1
        elif inst[1] == 'POPS':
            frames_and_stacks = pop_var_from_data_stack(frames_and_stacks, inst[2][0])
            current_inst += 1
        elif inst[1] == 'ADD':
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
            pass
        elif inst[1] == 'OR':
            pass
        elif inst[1] == 'NOT':
            pass
        elif inst[1] == 'INT2CHAR':
            pass
        elif inst[1] == 'STRI2INT':
            pass


if __name__ == '__main__':
    args: dict = parse_arguments()
    xml_tree: eT.ElementTree = parse_source()
    # format: [order, opcode, (arguments)[[order, type, value], ...], ...]
    instruction_list: list = parse_element_tree()
    print(instruction_list)
    execute()
