document.addEventListener('DOMContentLoaded', () => {
    const chartContainer = document.getElementById('doubleBarChart');
    const months = window.chartMonths || [];
    const courtData = window.courtRevenueData || [];
    const equipData = window.equipmentRevenueData || [];

    if (chartContainer && months.length > 0) {
        // Find highest value between court and equipment revenue to set proper height ratio
        const maxVal = Math.max(...courtData, ...equipData, 1);

        months.forEach((month, index) => {
            const courtVal = courtData[index] || 0;
            const equipVal = equipData[index] || 0;

            const courtPct = (courtVal / maxVal) * 100;
            const equipPct = (equipVal / maxVal) * 100;

            // Month Group Wrapper
            const group = document.createElement('div');
            group.style.display = 'flex';
            group.style.flexDirection = 'column';
            group.style.alignItems = 'center';
            group.style.height = '100%';
            group.style.justifyContent = 'flex-end';
            group.style.position = 'relative';

            // Bars Sub-container (Side-by-side)
            const barsContainer = document.createElement('div');
            barsContainer.style.display = 'flex';
            barsContainer.style.alignItems = 'flex-end';
            barsContainer.style.gap = '6px';
            barsContainer.style.height = '100%';

            // 1. Separate Court Revenue Bar
            const courtBar = document.createElement('div');
            courtBar.className = 'bar-fill';
            courtBar.style.width = '24px';
            courtBar.style.height = `${Math.max(courtPct, 4)}%`;
            courtBar.style.backgroundColor = '#2563eb';
            courtBar.style.borderRadius = '4px 4px 0 0';
            courtBar.title = `${month} (Court): RM ${courtVal.toFixed(2)}`;

            // 2. Separate Equipment Revenue Bar
            const equipBar = document.createElement('div');
            equipBar.className = 'bar-fill';
            equipBar.style.width = '24px';
            equipBar.style.height = `${Math.max(equipPct, 4)}%`;
            equipBar.style.backgroundColor = '#16a34a';
            equipBar.style.borderRadius = '4px 4px 0 0';
            equipBar.title = `${month} (Equipment): RM ${equipVal.toFixed(2)}`;

            // Month Label below the bars
            const label = document.createElement('div');
            label.className = 'bar-label';
            label.style.marginTop = '8px';
            label.style.fontSize = '0.8125rem';
            label.style.color = '#64748b';
            label.textContent = month;

            barsContainer.appendChild(courtBar);
            barsContainer.appendChild(equipBar);

            group.appendChild(barsContainer);
            group.appendChild(label);

            chartContainer.appendChild(group);
        });
    }
});