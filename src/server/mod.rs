use crate::server::settings::Settings;

mod settings;

pub async fn new() -> Result<Server, Box<dyn std::error::Error>> {
    let mut path = std::env::current_dir()?;
    path.push("config.yml");
    Ok(Server {
        settings: settings::load(&path).await?
    })
}

pub struct Server {
    settings: Settings
}