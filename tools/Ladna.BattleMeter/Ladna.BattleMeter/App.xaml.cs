using System.Windows;
using MessageBox = System.Windows.MessageBox;

namespace Ladna.BattleMeter;

public partial class App : System.Windows.Application
{
    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        DispatcherUnhandledException += (_, args) =>
        {
            MessageBox.Show(
                args.Exception.Message,
                "Ladna Battle Meter",
                MessageBoxButton.OK,
                MessageBoxImage.Error);
            args.Handled = true;
        };

        new MainWindow().Show();
    }
}
