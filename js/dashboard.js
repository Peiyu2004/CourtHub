document.addEventListener('DOMContentLoaded', () => {
    const chartContainer = document.getElementById('revenueBarChart');
    
    if (chartContainer && window.chartData && window.chartData.length > 0) {
        const maxValue = Math.max(...window.chartData, 1);

        window.chartData.forEach((value, index) => {
            const month = window.chartMonths[index] || '';
            const percentage = (value / maxValue) * 100;

            const col = document.createElement('div');
            col.className = 'bar-column';
            col.title = `${month}: $${value.toFixed(2)}`;

            const fill = document.createElement('div');
            fill.className = 'bar-fill';
            fill.style.height = `${Math.max(percentage, 5)}%`;

            const label = document.createElement('div');
            label.className = 'bar-label';
            label.textContent = month;

            col.appendChild(fill);
            col.appendChild(label);
            chartContainer.appendChild(col);
        });
    }
});