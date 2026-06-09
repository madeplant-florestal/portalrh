const PhoneUtils = require('../../assets/phone-utils.js');

const expectEq = (actual, expected, label) => {
  if (actual !== expected) {
    process.stderr.write(`Falha: ${label}: esperado "${expected}" mas veio "${actual}"\n`);
    process.exit(1);
  }
};

expectEq(PhoneUtils.digits('(67) 99169-9831'), '67991699831', 'digits remove formatação');
expectEq(PhoneUtils.digits('+55 (67) 99169-9831'), '67991699831', 'digits remove +55');
expectEq(PhoneUtils.normalize('679999-9999'), null, 'normalize rejeita inválido');
expectEq(PhoneUtils.normalize('67991699831'), '67991699831', 'normalize aceita 11 dígitos');
expectEq(PhoneUtils.format('67991699831'), '(67) 99169-9831', 'format aplica máscara');

process.stdout.write('OK phone-utils.test\n');
