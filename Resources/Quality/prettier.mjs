#!/usr/bin/env node

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

/*
 * The shared Prettier runner behind the `lint:htmlxml` and `fix:htmlxml`
 * npm scripts. It formats exactly the Prettier scope of a PSBits extension
 * - Classes, Configuration, Resources and Tests - and never the transient
 * directories below the repository root (`.Build`, `node_modules`, `var`,
 * ...). The prettier binary and its plugins are the caller's dev
 * dependencies; the repository root is taken from the current working
 * directory.
 *
 * Usage: node prettier.mjs <check|fix>
 *
 * check the Prettier scope without writing;
 * fix   write the Prettier format into the Prettier scope.
 *
 * The shared Prettier configuration lives next to this file; the
 * repository-specific ignore list `Build/Quality/.prettierignore` applies
 * when present.
 */

import {spawn} from 'node:child_process';
import {existsSync} from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const configPath = path.join(path.dirname(fileURLToPath(import.meta.url)), 'prettier.config.mjs');
const mode = process.argv[2];
const prettierBinary = path.join(process.cwd(), 'node_modules', '.bin', 'prettier');

if ('check' !== mode && 'fix' !== mode) {
    console.error('Usage: node prettier.mjs <check|fix>');
    process.exit(1);
}

if (!existsSync(prettierBinary)) {
    console.error(`prettier not found in ${path.join(process.cwd(), 'node_modules', '.bin')} (run npm install)`);
    process.exit(1);
}

const args = ['--config', configPath];

const ignorePath = path.join(process.cwd(), 'Build', 'Quality', '.prettierignore');

if (existsSync(ignorePath)) {
    args.push('--ignore-path', ignorePath);
}

args.push('check' === mode ? '--check' : '--write', '{Classes,Configuration,Resources,Tests}/**/*.{html,xml,xlf}');

const child = spawn(prettierBinary, args, {
    cwd: process.cwd(),
    stdio: 'inherit',
});

child.on('close', (code) => {
    process.exit(null === code ? 1 : code);
});
