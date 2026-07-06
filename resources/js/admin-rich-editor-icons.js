// Remap Jodit's toolbar icons to Remix Icon — the admin shell's icon set — via the public
// `Jodit.modules.Icon.set(name, svg)` API (dictionary of SVG strings, see jodit
// src/core/ui/icon.ts). No fork, survives upgrades. Keys are Jodit's internal icon names;
// where a button's name is ambiguous across Jodit versions several aliases point at the same
// SVG (extra dictionary entries are harmless — unmapped buttons just keep Jodit's default).
// SVGs use fill="currentColor", so they follow the admin theme like every other shell icon.

import alignCenter from 'remixicon/icons/Editor/align-center.svg?raw';
import alignJustify from 'remixicon/icons/Editor/align-justify.svg?raw';
import alignLeft from 'remixicon/icons/Editor/align-left.svg?raw';
import alignRight from 'remixicon/icons/Editor/align-right.svg?raw';
import bold from 'remixicon/icons/Editor/bold.svg?raw';
import codeView from 'remixicon/icons/Editor/code-view.svg?raw';
import fontColor from 'remixicon/icons/Editor/font-color.svg?raw';
import fontSize from 'remixicon/icons/Editor/font-size.svg?raw';
import formatClear from 'remixicon/icons/Editor/format-clear.svg?raw';
import indentDecrease from 'remixicon/icons/Editor/indent-decrease.svg?raw';
import indentIncrease from 'remixicon/icons/Editor/indent-increase.svg?raw';
import italic from 'remixicon/icons/Editor/italic.svg?raw';
import link from 'remixicon/icons/Editor/link.svg?raw';
import linkUnlink from 'remixicon/icons/Editor/link-unlink.svg?raw';
import listOrdered from 'remixicon/icons/Editor/list-ordered.svg?raw';
import listUnordered from 'remixicon/icons/Editor/list-unordered.svg?raw';
import omega from 'remixicon/icons/Editor/omega.svg?raw';
import paragraph from 'remixicon/icons/Editor/paragraph.svg?raw';
import separator from 'remixicon/icons/Editor/separator.svg?raw';
import strikethrough from 'remixicon/icons/Editor/strikethrough.svg?raw';
import table2 from 'remixicon/icons/Editor/table-2.svg?raw';
import text from 'remixicon/icons/Editor/text.svg?raw';
import underline from 'remixicon/icons/Editor/underline.svg?raw';
import arrowDown from 'remixicon/icons/Arrows/arrow-down-s-line.svg?raw';
import arrowGoBack from 'remixicon/icons/Arrows/arrow-go-back-line.svg?raw';
import arrowGoForward from 'remixicon/icons/Arrows/arrow-go-forward-line.svg?raw';
import fullscreenExit from 'remixicon/icons/Media/fullscreen-exit-line.svg?raw';
import fullscreen from 'remixicon/icons/Media/fullscreen-line.svg?raw';
import movieLine from 'remixicon/icons/Media/movie-line.svg?raw';
import checkLine from 'remixicon/icons/System/check-line.svg?raw';
import closeLine from 'remixicon/icons/System/close-line.svg?raw';
import deleteBin from 'remixicon/icons/System/delete-bin-line.svg?raw';
import pencil from 'remixicon/icons/Design/pencil-line.svg?raw';

export const JODIT_REMIX_ICONS = {
    bold,
    italic,
    underline,
    strikethrough,
    ul: listUnordered,
    ol: listOrdered,
    left: alignLeft,
    center: alignCenter,
    right: alignRight,
    justify: alignJustify,
    indent: indentIncrease,
    outdent: indentDecrease,
    link,
    unlink: linkUnlink,
    table: table2,
    th: table2,
    brush: fontColor,
    font: text,
    fontsize: fontSize,
    paragraph,
    eraser: formatClear,
    hr: separator,
    symbols: omega,
    omega,
    source: codeView,
    fullsize: fullscreen,
    video: movieLine,
    shrink: fullscreenExit,
    undo: arrowGoBack,
    redo: arrowGoForward,
    'angle-down': arrowDown,
    chevron: arrowDown,
    check: checkLine,
    ok: checkLine,
    cancel: closeLine,
    bin: deleteBin,
    pencil,
};
