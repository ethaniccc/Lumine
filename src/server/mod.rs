use crate::server::settings::Settings;
use tokio::task::JoinHandle;
use crate::Result;

type AsyncResult<T, E = Box<dyn std::error::Error + Send>> = std::result::Result<T, E>;

mod settings;

pub async fn new() -> Result<Server> {
    let mut path = std::env::current_dir()?;
    path.push("config.yml");
    let settings = settings::load(&path)?;
    Ok(Server {
        settings,
        handles: vec![]
    })
}

pub struct Server {
    pub settings: Settings,
    handles: Vec<JoinHandle<AsyncResult<()>>>
}

impl Server {
    pub async fn start(&mut self) -> Result<()>  {
        self.handles.push(tokio::spawn(async {
            //while (true)
            loop {

            }
            Ok(()) as AsyncResult<()>
        }));
        Ok(())
    }
}