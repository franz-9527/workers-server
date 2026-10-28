'use strict'

const fs = require('node:fs')
const path = require('node:path')

// WXML expressions use raw operators; HTML entities are valid only outside
// expressions or inside quoted string values. Do not run an HTML/XML encoder.
function expressionErrors(source, filename = '<wxml>') {
  const errors = []
  const bindings = /\{\{([\s\S]*?)\}\}/g
  let binding
  while ((binding = bindings.exec(source))) {
    const expression = binding[1]
    let quote = ''
    for (let i = 0; i < expression.length; i++) {
      const ch = expression[i]
      if (quote) {
        if (ch === '\\') i++
        else if (ch === quote) quote = ''
        continue
      }
      if (ch === '"' || ch === "'") { quote = ch; continue }
      const entity = expression.slice(i).match(/^&(?:amp|lt|gt|quot|apos|#\d+|#x[0-9a-f]+);/i)
      if (!entity) continue
      const offset = binding.index + 2 + i
      const before = source.slice(0, offset).split('\n')
      errors.push(filename + ':' + before.length + ':' + (before[before.length - 1].length + 1) +
        ' WXML 表达式含 HTML 转义 ' + entity[0] + '，请使用原始运算符')
      i += entity[0].length - 1
    }
  }
  return errors
}

function templates(root) {
  const result = []
  for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
    if (entry.name === 'node_modules' || entry.name === 'miniprogram_npm') continue
    const file = path.join(root, entry.name)
    if (entry.isDirectory()) result.push(...templates(file))
    else if (entry.isFile() && entry.name.endsWith('.wxml')) result.push(file)
  }
  return result.sort()
}

function check(root, compile = false) {
  const files = templates(root)
  if (!files.length) throw new Error('没有找到 WXML 模板：' + root)
  const errors = files.flatMap(file => expressionErrors(fs.readFileSync(file, 'utf8'), path.relative(root, file)))
  if (errors.length) throw new Error(errors.join('\n'))
  console.log('WXML 表达式转义检查通过：' + files.length + ' 个模板')
  if (compile) {
    const compiler = require('miniprogram-compiler')
    const result = compiler.wxmlToJs(root, {
      wxmlList: files.map(file => path.relative(root, file).replace(/\\/g, '/')),
    })
    if (typeof result !== 'string' || !result.trim()) throw new Error('WXML 编译器未返回有效产物')
    console.log('WXML 编译通过：' + files.length + ' 个模板')
  }
  return files.length
}

function selfTest() {
  const assert = require('node:assert/strict')
  assert.equal(expressionErrors('<view wx:if="{{isGroup &amp;&amp; item.isMine}}"/>').length, 2)
  assert.equal(expressionErrors('<view wx:if="{{isGroup && item.isMine}}"/>').length, 0)
  assert.equal(expressionErrors('<view wx:if="{{a &lt; b}}"/>').length, 1)
  assert.equal(expressionErrors('<view wx:if="{{a &#38;&#38; b}}"/>').length, 2)
  assert.equal(expressionErrors('<view>&amp; {{label}}</view>').length, 0)
  assert.equal(expressionErrors('<view wx:if="{{text === \'&amp;\'}}"/>').length, 0)
  assert.equal(expressionErrors('<view wx:if="{{text === \'it\\\'s &amp;\' && ready}}"/>').length, 0)
  assert.match(expressionErrors('<view>\n  {{a &amp;&amp; b}}\n</view>', 'sample.wxml')[0], /^sample\.wxml:2:7 /)
  console.log('WXML 检查自测通过')
}

if (require.main === module) {
  try {
    const args = process.argv.slice(2)
    if (args.includes('--self-test')) selfTest()
    else check(path.resolve(args.find(arg => !arg.startsWith('--')) || path.join(__dirname, '../miniprogram')), args.includes('--compile'))
  } catch (error) {
    console.error(error.message)
    process.exitCode = 1
  }
}

module.exports = { expressionErrors, templates, check }
