// The one name-column rule for every table-style admin list: the name text keeps at least 350px, plus 14px
// for each pixel of the member's text-size boost. Side columns fold into the line under the name, in the
// order given, until the name has that room. Screens call window.bsListFold from their Alpine state.

const nameMin = (boost = 0) => 350 + 14 * boost;

// textW: room the name text has with every column shown; cols: [[key, width], …] in folding order;
// returns the keys to fold. A column with no width (not drawn) is skipped and frees nothing.
const nameFold = (textW, cols, boost = 0) => {
    const min = nameMin(boost);
    const out = [];

    for (let i = 0; i < cols.length && textW < min; i++) {
        if (!(cols[i][1] > 0)) {
            continue;
        }
        textW += cols[i][1];
        out.push(cols[i][0]);
    }

    return out;
};

// The text-size boost in px, read from the shared token (stored in rem, 16px per rem).
const boostPx = () => (parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--user-text-boost')) || 0) * 16;

window.bsListFold = { nameMin, nameFold, boostPx };
