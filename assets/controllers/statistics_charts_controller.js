import { Controller } from '@hotwired/stimulus';
import { Chart, registerables } from 'chart.js';

// chart.js ships modular controllers/scales/elements; without /auto we
// register them ourselves so the bare `chart.js` importmap entry is enough
// (no separate `chart.js/auto` mapping needed).
Chart.register(...registerables);

/**
 * Renders the two charts on the admin statistics dashboard:
 *  - monthly leave-distribution bar chart (12 months × hours)
 *  - department utilization bar chart (one bar per non-hidden department)
 *
 * Reads pre-aggregated data from `data-statistics-charts-*-value` so we
 * don't need a JSON endpoint — the dashboard renders in one round-trip.
 *
 * Hidden departments (k-anonymity) are filtered out client-side so the
 * chart never reveals their cell — the dashboard table below the chart
 * still shows them with the placeholder, which is the right place.
 */
export default class extends Controller {
    static targets = ['monthlyChart', 'departmentsChart'];

    static values = {
        monthly: Array,
        departments: Array,
        monthLabels: Array,
        monthlyLabel: String,
        utilizationLabel: String,
        hoursLabel: String,
    };

    connect() {
        this.charts = [];
        if (this.hasMonthlyChartTarget) {
            this.charts.push(this.renderMonthlyChart());
        }
        if (this.hasDepartmentsChartTarget) {
            this.charts.push(this.renderDepartmentsChart());
        }
    }

    disconnect() {
        for (const chart of this.charts) {
            chart?.destroy();
        }
        this.charts = [];
    }

    renderMonthlyChart() {
        // monthlyValue is a 12-entry array Jan..Dec (0-indexed) — PHP emits
        // it as a JSON array via array_values so the Stimulus Array value
        // type accepts it directly.
        return new Chart(this.monthlyChartTarget, {
            type: 'bar',
            data: {
                labels: this.monthLabelsValue,
                datasets: [
                    {
                        label: this.monthlyLabelValue,
                        data: this.monthlyValue,
                        backgroundColor: 'rgba(59, 130, 246, 0.65)',
                        borderColor: 'rgb(59, 130, 246)',
                        borderWidth: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: this.hoursLabelValue },
                    },
                },
            },
        });
    }

    renderDepartmentsChart() {
        const visible = this.departmentsValue.filter((d) => !d.hidden);
        const labels = visible.map((d) => d.name);
        const data = visible.map((d) => d.utilization ?? 0);

        return new Chart(this.departmentsChartTarget, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: this.utilizationLabelValue,
                        data,
                        backgroundColor: 'rgba(16, 185, 129, 0.65)',
                        borderColor: 'rgb(16, 185, 129)',
                        borderWidth: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: (value) => `${value} %`,
                        },
                    },
                },
            },
        });
    }
}
