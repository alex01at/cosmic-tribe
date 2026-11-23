const Format = {
    number(n) {
        return new Intl.NumberFormat('de-DE').format(Math.floor(n));
    },
    duration(totalSeconds) {
        totalSeconds = Math.max(0, Math.floor(totalSeconds));
        const h = Math.floor(totalSeconds / 3600);
        const m = Math.floor((totalSeconds % 3600) / 60);
        const s = totalSeconds % 60;
        if (h > 0) return `${h}h ${m}m`;
        if (m > 0) return `${m}m ${s}s`;
        return `${s}s`;
    },
    outcomeLabel(outcome) {
        return { attacker_win: 'Angreifer siegt', defender_win: 'Verteidiger siegt', draw: 'Unentschieden' }[outcome] || outcome;
    },
};
