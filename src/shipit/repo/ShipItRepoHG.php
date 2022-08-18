<?hh
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 * @format
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/bliil915
 */
namespace Facebook\ShipIt;

use namespace HH\Lib\{C, Keyset, Regex, Str, Vec}; // @oss-enable

final class ShipItRepoHGException extends ShipItRepoException {}

/**
 * HG specialization of ShipItRepo
 */
class ShipItRepoHG extends ShipItRepo {
  private ?string $branch;
  const string COMMIT_SEPARATOR = '-~-~-~';
  const string SHIPIT_DISABLE_HG_NATIVE_PATCH_RENDERING_ENV_KEY =
    'SHIPIT_DISABLE_HG_NATIVE_PATCH_RENDERING';

  <<__Override>>
  public async function genSetBranch(string $branch): Awaitable<bool> {
    $this->branch = $branch;
    try {
      await $this->genHgCommand('root');
    } catch (ShipItRepoException $_ex) {
      throw new ShipItRepoHGException($this, "{$this->path} is not a HG repo");
    }
    return true;
  }

  <<__Override>>
  public async function genUpdateBranchTo(string $base_rev): Awaitable<void> {
    $branch = $this->branch;
    if ($branch === null) {
      throw new ShipItRepoHGException($this, 'setBranch must be called first.');
    }
    await $this->genHgCommand(
      'bookmark',
      '--force',
      '--rev',
      $base_rev,
      $branch,
    );
    await $this->genHgCommand('update', $branch);
  }

  <<__Override>>
  public async function genHeadChangeset(): Awaitable<?ShipItChangeset> {
    $branch = $this->branch;
    if ($branch === null) {
      throw new ShipItRepoHGException($this, "setBranch must be called first.");
    }
    $log = await $this->genHgCommand(
      'log',
      '--limit',
      '1',
      '-r',
      $branch,
      '--template',
      '{node}\\n',
    );
    $log = Str\trim($log);
    if ($log === '') {
      return null;
    }
    if (Str\length($log) !== 40) {
      throw new ShipItRepoHGException(
        $this,
        "{$log} doesn't look like a valid"." hg changeset id",
      );
    }
    return await $this->genChangesetFromID($log);
  }

  public async function genFindNextCommit(
    string $revision,
    keyset<string> $roots,
  ): Awaitable<?string> {
    $branch = $this->branch;
    if ($branch === null) {
      throw new ShipItRepoHGException($this, "setBranch must be called first.");
    }
    $log = await $this->genHgCommand(
      'log',
      '--limit',
      '1',
      '-r',
      "({$revision}::'{$branch}') - {$revision}",
      '--template',
      '{node}\\n',
      ...$roots
    );
    $log = Str\trim($log);
    if ($log === '') {
      return null;
    }
    if (Str\length($log) !== 40) {
      throw new ShipItRepoHGException(
        $this,
        "{$log} doesn't look like a valid"." hg changeset id",
      );
    }
    return $log;
  }

  public async function genFindLastSourceCommit(
    keyset<string> $roots,
    string $commit_marker,
  ): Awaitable<?string> {
    $log = await $this->genHgCommand(
      'log',
      '--limit',
      '1',
      '--keyword',
      Str\format('%s:', $commit_marker),
      '--template',
      '{desc}',
      ...$roots
    );
    $log = Str\trim($log);
    return ShipItSync::getTrackingDataFromString($log, $commit_marker);
  }

  public async function genCommitPatch(
    ShipItChangeset $patch,
    bool $_do_submodules = true, // Not relevant for hg
  ): Awaitable<string> {
    if (C\is_empty($patch->getDiffs())) {
      // This is an empty commit, which `hg patch` does not handle properly.
      await $this->genHgCommand(
        '--config',
        'ui.allowemptycommit=True',
        'commit',
        '--user',
        $patch->getAuthor(),
        '--date',
        PHP\date('c', $patch->getTimestamp()),
        '-m',
        self::getCommitMessage($patch),
      );
    } else {
      $diff = self::renderPatch($patch);
      await $this->genHgPipeCommand($diff, 'patch', '-');
    }
    $id = (await $this->genChangesetFromID('.'))?->getID();
    invariant($id !== null, 'Unexpected null SHA!');
    return $id;
  }

  public static function renderPatch(ShipItChangeset $patch): string {

    $return = self::shouldDisableHgNativePatchRendering()
      ? self::renderGitHeader($patch)
      : self::renderHgHeader($patch);

    // Render the body as a Git extended diff format (original)
    // Note: This is not the default format consumed by Hg externally but is for Meta's
    // internal hg client
    $return .= self::renderGitExtendedDiffPatchBodyForPatch($patch);

    return $return;
  }

  /**
   * Determines if HG native patch rendering should be disabled.
   *
   * This condition is met if environment variable 'SHIPIT_DISABLE_HG_NATIVE_PATCH_RENDERING'
   * is set as long as that value is not 'false' (case insensitive)
   */
  private static function shouldDisableHgNativePatchRendering(): bool {
    $disable_hg_native_patch_rendering_raw_value =
      ShipItEnv::getEnv(self::SHIPIT_DISABLE_HG_NATIVE_PATCH_RENDERING_ENV_KEY);

    if ($disable_hg_native_patch_rendering_raw_value is null) {
      return false;
    }

    $comparison = Str\compare_ci(
      Str\trim($disable_hg_native_patch_rendering_raw_value),
      'false',
    ) !==
      0;

    return $comparison;
  }

  private static function renderGitExtendedDiffPatchBodyForPatch(
    ShipItChangeset $patch,
  ): string {
    $return = "";
    foreach ($patch->getDiffs() as $diff) {
      $path = $diff['path'];
      $new_path = Shapes::idx($diff, 'new_path', $path) ?? $path;
      $body = $diff['body'];

      $return .= "diff --git a/{$path} b/{$new_path}\n{$body}\n";
    }
    $return .= "--\n1.7.9.5\n";

    return $return;
  }

  private static function renderHgHeader(ShipItChangeset $patch): string {
    // Mercurial's internal date format treats offset counter to timezones in terms of sign,
    // with the value "in seconds west of UTC (negative if the timezone is east of UTC)."
    // see: https://www.mercurial-scm.org/repo/hg/help/dates
    $timezone_offset_seconds = (new \DateTime())->getOffset() * -1;

    $commit_message = self::getCommitMessage($patch);
    $hg_header = "# HG changeset patch\n".
      "# User {$patch->getAuthor()}\n".
      "# Date {$patch->getTimestamp()} {$timezone_offset_seconds}\n".
      "#      ".
      PHP\date('r', $patch->getTimestamp()).
      "\n".
      "# Node ID {$patch->getID()}\n".
      "# Parent  0000000000000000000000000000000000000000\n".
      "{$commit_message}\n\n";

    return $hg_header;
  }

  private static function renderGitHeader(ShipItChangeset $patch): string {
    // Mon Sep 17 is a magic date used by format-patch to distinguish from real
    // mailboxes. cf. https://git-scm.com/docs/git-format-patch
    $commit_message = self::getCommitMessage($patch)
      |> self::fixInlinePatchesInCommitMessage($$);
    $ret = "From {$patch->getID()} Mon Sep 17 00:00:00 2001\n".
      "From: {$patch->getAuthor()}\n".
      "Date: ".
      PHP\date('r', $patch->getTimestamp()).
      "\n".
      "Subject: [PATCH] {$commit_message}\n---\n\n";

    return $ret;
  }

  public async function genPush(): Awaitable<void> {
    $branch = $this->branch;
    if ($branch === null) {
      throw new ShipItRepoHGException($this, 'setBranch must be called first.');
    }
    await $this->genHgCommand('push', '--branch', $branch);
  }

  /*
   * Generator yielding patch sections of the diff blocks (individually).
   */
  private static function parseHgRegions(string $patch): Iterator<string> {
    $contents = '';
    foreach (Str\split($patch, "\n") as $line) {
      $line = Regex\replace($line, re"/(\r\n|\n)/", "\n");

      if (
        $contents !== '' &&
        Regex\matches(
          Str\trim_right($line),
          re"@^diff --git( ([ab]/(.*?)|/dev/null)){2}@",
        )
      ) {
        yield $contents;
        $contents = '';
      }
      $contents .= $line."\n";
    }
    if ($contents !== '') {
      yield $contents;
    }
  }

  private static function parseHeader(string $header): ShipItChangeset {
    $changeset = new ShipItChangeset();

    $subject = null;
    $message = '';
    $past_separator = false;
    foreach (Str\split($header, "\n") as $line) {
      if (!$past_separator && $line === self::COMMIT_SEPARATOR) {
        $past_separator = true;
        continue;
      }
      if (Str\length($line) === 0) {
        $message .= "\n";
        continue;
      }
      if ($line[0] === '#' && !$past_separator) {
        if (Str\starts_with_ci($line, '# User ')) {
          $changeset = $changeset->withAuthor(Str\slice($line, 7));
          if (!Regex\matches($changeset->getAuthor(), re"/.*<.*>/")) {
            $changeset = $changeset->withAuthor(
              Str\format('%s <>', $changeset->getAuthor()),
            );
          }
        } else if (Str\starts_with_ci($line, '# Date ')) {
          $changeset = $changeset->withTimestamp((int)Str\slice($line, 7));
        }
        // Ignore anything else in the envelope
        continue;
      }
      if ($subject === null) {
        $subject = $line;
        continue;
      }
      $message .= "{$line}\n";
    }

    return $changeset
      ->withSubject((string)$subject)
      ->withMessage(Str\trim($message));
  }

  public async function genNativePatchFromID(
    string $revision,
  ): Awaitable<string> {
    return await $this->genHgCommand(
      'log',
      '--config',
      'diff.git=True',
      '-r',
      $revision,
      '--encoding',
      'UTF-8',
      '--template',
      '{diff()}',
    );
  }

  public async function genNativeHeaderFromID(
    string $revision,
  ): Awaitable<string> {
    return await $this->genHgCommand(
      'log',
      '--config',
      'diff.git=True',
      '-r',
      $revision,
      '--encoding',
      'UTF-8',
      '--template',
      '# User {author}
# Date {date}
# Node ID {node}
'.
      self::COMMIT_SEPARATOR.
      '
{desc}',
    );
  }

  public async function genChangesetFromID(
    string $revision,
  ): Awaitable<?ShipItChangeset> {
    $header = await $this->genNativeHeaderFromID($revision);
    $patch = await $this->genNativePatchFromID($revision);
    $changeset =
      await $this->genChangesetFromNativePatch($revision, $header, $patch);
    return $changeset;
  }

  private async function genChangesetFromNativePatch(
    string $revision,
    string $header,
    string $patch,
  ): Awaitable<ShipItChangeset> {
    $changeset = self::getChangesetFromExportedPatch($header, $patch);
    // we need to have plain diffs for each file, and rename/copy from
    // breaks this, and we can't turn it off in hg.
    //
    // for example, if the change to 'proprietary/foo.cpp' is removed,
    // but 'public/foo.cpp' is not, this breaks:
    //
    //   rename from proprietary/foo.cpp to public/foo.cpp
    //
    // If we have any matching files, re-create their diffs using git, which
    // will do full diffs for both sides of the copy/rename.
    $has_mode_change = $changeset->getDiffs()
      |> Vec\filter(
        $$,
        $diff ==> Regex\matches($diff['body'], re"/^old mode/m"),
      )
      |> Keyset\map($$, $diff ==> $diff['path']);

    if ($this->useNativeRenames) {
      $needs_git = $has_mode_change;
    } else {
      // unsupported rename or copy
      $matches = Regex\every_match(
        $patch,
        re"/^(?:rename|copy) (?:from|to) (?<files>.+)$/m",
      );
      $has_rename_or_copy = Keyset\map($matches, $m ==> $m['files']);
      $needs_git = Keyset\union($has_rename_or_copy, $has_mode_change);
    }

    if ($needs_git) {
      $diffs = Vec\filter(
        $changeset->getDiffs(),
        $diff ==> !C\contains($needs_git, $diff['path']),
      );
      $diffs = Vec\concat(
        $diffs,
        await $this->genMakeDiffsUsingGit($revision, $needs_git),
      );
      $changeset = $changeset->withDiffs($diffs);
    }

    return $changeset->withID($revision);
  }

  <<__Override>>
  public static function getDiffsFromPatch(string $patch): vec<ShipItDiff> {
    $diffs = vec[];
    foreach (self::parseHgRegions($patch) as $region) {
      $diff = self::parseDiffHunk($region);
      if ($diff !== null) {
        $diffs[] = $diff;
      }
    }
    return $diffs;
  }

  public static function getChangesetFromExportedPatch(
    string $header,
    string $patch,
  ): ShipItChangeset {
    $changeset = self::parseHeader($header);
    return $changeset->withDiffs(self::getDiffsFromPatch($patch));
  }

  protected async function genHgPipeCommand(
    ?string $stdin,
    string ...$args
  ): Awaitable<string> {
    // Some server-side commands will inexplicitly fail, and then succeed the
    // next time they are ran.  There are a some, however, that we never want
    // to re-run because we'll lose error messages as a result.
    switch (C\first($args) ?? '') {
      case 'patch':
        $retry_count = 0;
        break;
      default:
        $retry_count = 1;
    }

    $command = (new ShipItShellCommand($this->path, 'hg', ...$args))
      ->setEnvironmentVariables(dict['HGPLAIN' => '1'])
      ->setRetries($retry_count);
    if ($stdin !== null) {
      $command->setStdIn($stdin);
    }
    return (await $command->genRun())->getStdOut();
  }

  protected async function genHgCommand(string ...$args): Awaitable<string> {
    return await $this->genHgPipeCommand(null, ...$args);
  }

  <<__Override>>
  public async function genClean(): Awaitable<void> {
    await $this->genHgCommand('purge', '--all');
  }

  <<__Override>>
  public async function genPushLfs(
    string $_pull_endpoint,
    string $_push_endpoint,
  ): Awaitable<void> {
    throw new ShipItRepoHGException($this, "push lfs not implemented for hg");
  }

  <<__Override>>
  public async function genPull(): Awaitable<void> {
    await $this->genHgCommand('pull');
  }

  <<__Override>>
  public async function genOrigin(): Awaitable<string> {
    return Str\trim(await $this->genHgCommand('config', 'paths.default'));
  }

  private async function genMakeDiffsUsingGit(
    string $rev,
    keyset<string> $files,
  ): Awaitable<vec<ShipItDiff>> {
    $tempdir = new ShipItTempDir('git-wd');
    $path = $tempdir->getPath();

    await $this->genCheckoutFilesAtRevToPath($files, $rev.'^', $path.'/a');
    await $this->genCheckoutFilesAtRevToPath($files, $rev, $path.'/b');

    $result = await (
      new ShipItShellCommand(
        $path,
        'git',
        'diff',
        '--binary',
        '--no-prefix',
        '--no-renames',
        'a',
        'b',
      )
    )->setNoExceptions()->genRun();

    invariant(
      $result->getExitCode() === 1,
      'git diff exited with %d, which means no changes; expected 1, '.
      'which means non-empty diff.',
      $result->getExitCode(),
    );
    $patch = $result->getStdOut();

    $diffs = vec[];
    foreach (self::parsePatch($patch) as $hunk) {
      $diff = self::parseDiffHunk($hunk);
      if ($diff !== null) {
        $diffs[] = $diff;
      }
    }
    return $diffs;
  }

  private async function genCheckoutFilesAtRevToPath(
    keyset<string> $files,
    string $rev,
    string $path,
  ): Awaitable<void> {
    /* Use a list of patterns from a file (/dev/stdin) instead
     * of specifying on the command line - otherwise, we can
     * generate a command that is larger than the maximum length
     * allowed by the system, so, exec() won't actually execute.
     *
     * In the case of zero files passed, assume that means we're exporting
     * the root, otherwise archive will fail.
     *
     * Example diff:
     *   rFBSed54f611dc0aebe17010b3416e64549d95ee3a49
     *   ... which is https://github.com/facebook/nuclide/commit/2057807d2653dd1af359f44f658eadac6eaae34b
     */
    if (C\is_empty($files)) {
      $files = keyset['.'];
    }
    $patterns = Keyset\map($files, $file ==> 'path:'.$file)
      |> Str\join($$, "\n");

    // Prefetch is needed for reasonable performance with the remote file
    // log extension
    using ($this->getSharedLock()->getExclusive()) {
      try {
        await $this->genHgPipeCommand(
          $patterns,
          'prefetch',
          '-r',
          $rev,
          'listfile:/dev/stdin',
        );
      } catch (ShipItShellCommandException $_e) {
        // ignore, not all repos are shallow
      }
    }

    await $this->genHgPipeCommand(
      $patterns,
      'archive',
      '--config',
      'ui.archivemeta=False',
      '-r',
      $rev,
      '-I',
      'listfile:/dev/stdin',
      $path,
    );
  }

  public async function genExport(
    keyset<string> $roots,
    bool $_do_submodules, // Not relevant for hg
    ?string $rev = null,
  ): Awaitable<shape('tempDir' => ShipItTempDir, 'revision' => string)> {
    $branch = $this->branch;
    if ($branch === null) {
      throw new ShipItRepoHGException($this, 'setBranch must be called first.');
    }
    if ($rev === null) {
      $rev = await $this->genHgCommand('log', '-r', $branch, '-T', '{node}');
    }

    $temp_dir = new ShipItTempDir('hg-export');
    await $this->genCheckoutFilesAtRevToPath(
      $roots,
      $rev,
      $temp_dir->getPath(),
    );

    return shape('tempDir' => $temp_dir, 'revision' => $rev);
  }

  public async function genFileContents(
    string $rev,
    string $path,
  ): Awaitable<string> {
    return await $this->genHgCommand('cat', '-r', $rev, $path);
  }
}
